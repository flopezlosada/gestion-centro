<?php

declare(strict_types=1);

namespace App\Space;

use App\Entity\AcademicYear;
use App\Entity\ScheduleEntry;
use App\Entity\SpacePlan;
use App\Entity\User;
use App\Enum\Weekday;
use App\Repository\ScheduleEntryRepository;
use App\Repository\SpacePlanAssignmentRepository;
use App\Repository\SpacePlanRepository;

/**
 * What a person's timetable says for a PARTICULAR DATE, as opposed to for a weekday.
 *
 * The counterpart of {@see RoomOccupancy}: that one answers "who is in this room", this one answers "what
 * does this teacher have now". Both compose the same two layers — the weekly grid imported from Peñalara
 * plus the lines of the approved {@see SpacePlan}s — and for the same reason: once one plan is approved,
 * reading {@see \App\Entity\ScheduleEntry} on its own gives an answer that is wrong in two ways.
 *
 * It can be wrong about WHERE: a relocated lesson is held in another room, so a guardia parte that
 * photographs the timetable's room sends the covering teacher to an empty classroom.
 *
 * And it can be wrong about WHETHER: during exam week 2º de Bachillerato has no ordinary lessons at all
 * ({@see SpacePlan::replacesTimetableFor()}), so the cells are still in the table but the classes do not
 * happen. Registering an absence against them would create parte lines, tasks and notices for lessons
 * nobody was going to teach.
 */
final class EffectiveTimetable
{
    public function __construct(
        private readonly ScheduleEntryRepository $schedule,
        private readonly SpacePlanAssignmentRepository $assignments,
        private readonly SpacePlanRepository $plans,
    ) {
    }

    /**
     * The lessons a teacher really has at one period of one date, each with the room it is really held in.
     *
     * Empty when they teach nothing then — whether because the timetable gives them a free period or
     * because an approved plan has replaced their groups' timetable that day. Callers cannot tell the two
     * apart, and do not need to: in both cases there is nothing to cover.
     *
     * @param AcademicYear       $year      the course the date falls into (supplies the timetable)
     * @param User               $teacher   the teacher
     * @param \DateTimeImmutable $date      the day
     * @param int                $slotIndex the period index within the day
     *
     * @return list<EffectiveLesson> the lessons that period, in the timetable's own order
     */
    public function forTeacherAt(AcademicYear $year, User $teacher, \DateTimeImmutable $date, int $slotIndex): array
    {
        $weekday = Weekday::from((int) $date->format('N'));
        $entries = $this->schedule->lectiveEntriesAt($year, $teacher, $weekday, $slotIndex);
        if ([] === $entries) {
            return [];
        }

        $plans = new ApprovedPlans($this->plans->approvedCovering($date));
        // No approved plan touches this day: the ordinary timetable IS the effective one, and the second
        // query is not worth making. The common case by a wide margin.
        if ($plans->isEmpty()) {
            return array_map(static fn (ScheduleEntry $entry): EffectiveLesson => new EffectiveLesson($entry), array_values($entries));
        }

        $movedTo = [];
        foreach ($this->assignments->inForceAt($date, $slotIndex) as $line) {
            $sourceId = $line->getSourceEntry()?->getId();
            if (null !== $sourceId) {
                $movedTo[$sourceId] = $line;
            }
        }

        $lessons = [];
        foreach ($entries as $entry) {
            if ($plans->replaceTimetableFor($date, $slotIndex, $entry->getGroupName())) {
                continue;
            }

            $line = $movedTo[(int) $entry->getId()] ?? null;
            $lessons[] = new EffectiveLesson($entry, $line?->getRoom());
        }

        return $lessons;
    }
}
