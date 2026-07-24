<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Entity\Absence;
use App\Entity\AcademicYear;
use App\Entity\GuardiaCover;
use App\Entity\ScheduleEntry;
use App\Entity\User;
use App\Enum\Weekday;
use App\Repository\AbsenceRepository;
use App\Repository\GuardiaCoverRepository;
use App\Repository\ScheduleEntryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Registers a teacher's absence in one step and lets the equitable engine take over: given the day
 * (and either specific periods or the teacher's whole teaching day), it creates a parte line for
 * each period the teacher actually teaches — a free period or a duty needs no cover — snapshotting
 * the uncovered group and room from the timetable, and then runs {@see GuardiaScheduler} for each
 * affected period (which assigns a guardia and notifies them).
 *
 * This is the single entry point behind both the coordinator's "apuntar ausencia" screen and a
 * teacher self-reporting their own absence, so the "register → auto-assign → notify" flow lives in
 * one place.
 */
final class AbsenceRegistrar
{
    public function __construct(
        private readonly ScheduleEntryRepository $schedule,
        private readonly GuardiaCoverRepository $covers,
        private readonly AbsenceRepository $absences,
        private readonly GuardiaScheduler $scheduler,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Registers the absence and runs the assignment.
     *
     * @param AcademicYear       $year        the course the date falls into (supplies the timetable)
     * @param User               $teacher     the absent teacher
     * @param \DateTimeImmutable $date        the day of the absence
     * @param list<int>|null     $slotIndexes the periods to register, or null for the whole teaching day
     * @param string|null        $reason      the private reason for the absence; only set when non-empty,
     *                                         so re-registering more periods without retyping it keeps it
     * @param array<int, array{documentPath?: ?string, documentName?: ?string, description?: ?string}> $taskBySlot
     *                                         per-period task (slot index → the group's document and/or
     *                                         description); each period/group carries its own work
     *
     * @return AbsenceRegistrationResult what was created and what was skipped
     */
    public function register(AcademicYear $year, User $teacher, \DateTimeImmutable $date, ?array $slotIndexes, ?string $reason, array $taskBySlot = []): AbsenceRegistrationResult
    {
        $weekday = Weekday::from((int) $date->format('N'));
        $slots = $slotIndexes ?? $this->schedule->lectiveSlotsFor($year, $teacher, $weekday);

        // One absence per (teacher, day): reuse it if the day is already partly registered, so the
        // reason stays single-sourced. Built in memory and only persisted once a cover is actually
        // created, so an all-skipped registration never leaves an orphan absence (with its reason).
        $absence = $this->absences->findForTeacherAndDate($teacher, $date);
        $absenceIsNew = null === $absence;
        if ($absenceIsNew) {
            $absence = (new Absence())->setAbsentTeacher($teacher)->setDate($date);
        }

        $createdSlots = [];
        $skippedFree = 0;
        $skippedExisting = 0;
        foreach (array_values(array_unique($slots)) as $slotIndex) {
            // A period may hold several classes at once (a multi-group activity in the assembly hall);
            // it is still ONE guardia to cover, so all its groups/rooms fold into a single cover.
            $entries = $this->schedule->lectiveEntriesAt($year, $teacher, $weekday, $slotIndex);
            if ([] === $entries) {
                ++$skippedFree;
                continue;
            }
            if (null !== $this->covers->findOneBy(['absentTeacher' => $teacher, 'date' => $date, 'slotIndex' => $slotIndex])) {
                ++$skippedExisting;
                continue;
            }

            // Persist the (possibly new) absence lazily, on the first cover that will actually exist.
            if ($absenceIsNew && [] === $createdSlots) {
                $this->em->persist($absence);
            }

            $task = $taskBySlot[$slotIndex] ?? [];
            $this->em->persist((new GuardiaCover())
                ->setAbsence($absence)
                ->setDate($date)
                ->setSlotIndex($slotIndex)
                ->setAbsentTeacher($teacher)
                ->setGroupName(self::snapshot(array_map(static fn (ScheduleEntry $e): ?string => $e->getGroupName(), $entries)))
                ->setRoomName(self::snapshot(array_map(static fn (ScheduleEntry $e): ?string => $e->getRoomName(), $entries)))
                ->setTaskDocumentPath($task['documentPath'] ?? null)
                ->setTaskDocumentName($task['documentName'] ?? null)
                ->setTaskDescription($task['description'] ?? null));
            $createdSlots[] = $slotIndex;
        }

        // Apply the reason only when the absence is (or already was) real: a fresh, non-empty reason on
        // a day that produced a cover, or an update to an already-persisted absence. Never on a brand-new
        // absence that ends up with no covers (nothing is flushed in that case, so no orphan).
        if ((null !== $reason && '' !== trim($reason)) && ([] !== $createdSlots || !$absenceIsNew)) {
            $absence->setReason($reason);
        }
        $this->em->flush();

        // Assign each affected period after the covers exist, so the balance sees them all.
        foreach ($createdSlots as $slotIndex) {
            $this->scheduler->autoAssign($year, $date, $slotIndex);
        }

        return new AbsenceRegistrationResult($createdSlots, $skippedFree, $skippedExisting);
    }

    /**
     * Folds the group (or room) names of a period's classes into one snapshot string: distinct,
     * non-empty, ", "-separated. A single-class period yields just its name (the common case is
     * unchanged); a multi-group activity keeps every group/room instead of losing all but one. Capped
     * to fit the snapshot column, never silently dropping data mid-value.
     *
     * @param list<string|null> $values the per-class names (groups or rooms)
     *
     * @return string|null the folded snapshot, or null when there is nothing to keep
     */
    private static function snapshot(array $values): ?string
    {
        $distinct = array_values(array_unique(array_filter(
            array_map(static fn (?string $v): string => null !== $v ? trim($v) : '', $values),
            static fn (string $v): bool => '' !== $v,
        )));
        if ([] === $distinct) {
            return null;
        }

        $joined = implode(', ', $distinct);

        return mb_strlen($joined) > 255 ? mb_substr($joined, 0, 254).'…' : $joined;
    }
}
