<?php

declare(strict_types=1);

namespace App\Space;

use App\Entity\AcademicYear;
use App\Entity\Room;
use App\Enum\Weekday;
use App\Repository\RoomRepository;
use App\Repository\ScheduleEntryRepository;
use App\Repository\SpacePlanAssignmentRepository;
use App\Repository\SpacePlanRepository;

/**
 * Who is in which space at a given moment, and therefore which spaces are free.
 *
 * The single place that answers that question, because several screens need the same answer and must
 * not disagree: the "aulas libres" consultation, the guardia coordinator looking for a big room to
 * merge groups into, and — from the next phase on — the engine that proposes where a displaced group
 * can go.
 *
 * It answers on the EFFECTIVE timetable, not the ordinary one: the weekly grid imported from Peñalara
 * plus whatever the approved {@see \App\Entity\SpacePlan}s say about that particular day. A relocated
 * lesson frees the room it came from and takes the one it went to; a group whose timetable a plan
 * replaces (2º de Bachillerato during exam week) is in none. A plan that is not approved changes
 * nothing here — that is the whole meaning of approving one.
 *
 * ONE LIMITATION, STATED UP FRONT: the ordinary half of that answer is the timetable, and the timetable
 * only knows about lessons. A meeting, a rehearsal or a talk booked verbally occupies a room that this
 * service will report as free. That is why the screen is called "aulas libres SEGÚN EL HORARIO" and why
 * a room can be marked non-assignable ({@see Room::isAssignable()}) — the honest answer is "the
 * timetable puts nobody here", not "this room is free".
 */
final class RoomOccupancy
{
    public function __construct(
        private readonly RoomRepository $rooms,
        private readonly ScheduleEntryRepository $schedule,
        private readonly SpacePlanAssignmentRepository $assignments,
        private readonly SpacePlanRepository $plans,
    ) {
    }

    /**
     * The occupancy of every catalogued space at one period of one date.
     *
     * @param AcademicYear       $year      the course the date falls into (supplies the timetable)
     * @param \DateTimeImmutable $date      the day
     * @param int                $slotIndex the period index within the day
     *
     * @return RoomAvailability the free and the occupied spaces at that period
     */
    public function at(AcademicYear $year, \DateTimeImmutable $date, int $slotIndex): RoomAvailability
    {
        $weekday = Weekday::from((int) $date->format('N'));

        // What the approved plans say about this moment, read before the ordinary timetable because they
        // override it: a moved lesson no longer occupies the room it came from, and a group whose
        // timetable a plan replaces (2º de Bachillerato during exam week) is not in a classroom at all.
        $lines = $this->assignments->inForceAt($date, $slotIndex);
        $plans = $this->plans->approvedCovering($date);

        $moved = [];
        foreach ($lines as $line) {
            $sourceId = $line->getSourceEntry()?->getId();
            if (null !== $sourceId) {
                $moved[$sourceId] = true;
            }
        }

        // Several occupants can share one room in the same period: a split group (desdoble) or a
        // whole-level activity is listed once per group. Fold them into one occupation per room, or the
        // screen would report the same room twice and the free list would still be right by accident.
        $entriesByRoom = [];
        foreach ($this->schedule->occupancyAt($year, $weekday, $slotIndex) as $entry) {
            $room = $entry->getRoom();
            if (null === $room || null === $room->getId() || isset($moved[$entry->getId()])) {
                continue;
            }
            if ($this->timetableReplaced($plans, $date, $slotIndex, $entry->getGroupName())) {
                continue;
            }

            $entriesByRoom[$room->getId()][] = $entry;
        }

        $linesByRoom = [];
        foreach ($lines as $line) {
            $roomId = $line->getRoom()?->getId();
            if (null !== $roomId) {
                $linesByRoom[$roomId][] = $line;
            }
        }

        $free = [];
        $occupied = [];
        foreach ($this->rooms->findActive() as $room) {
            $entries = $entriesByRoom[$room->getId()] ?? [];
            $roomLines = $linesByRoom[$room->getId()] ?? [];
            if ([] === $entries && [] === $roomLines) {
                $free[] = $room;
                continue;
            }

            $occupied[] = new RoomOccupation($room, $entries, $roomLines);
        }

        return new RoomAvailability($free, $occupied);
    }

    /**
     * Whether some approved plan takes the given group out of the ordinary timetable at that moment.
     *
     * @param list<\App\Entity\SpacePlan> $plans     the approved plans covering the day
     * @param \DateTimeImmutable          $date      the day
     * @param int                         $slotIndex the period index
     * @param string|null                 $groupName the group, or null for a cell with no group
     *
     * @return bool true when that group has no ordinary lesson then
     */
    private function timetableReplaced(array $plans, \DateTimeImmutable $date, int $slotIndex, ?string $groupName): bool
    {
        foreach ($plans as $plan) {
            if ($plan->covers($date, $slotIndex) && $plan->replacesTimetableFor($groupName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The spaces a displaced group could be sent to at that period — {@see RoomAvailability::candidates()}
     * over one period's occupancy. For callers that need only the candidates (the proposal engine); a
     * screen showing both the free and the occupied ones should read {@see at()} once instead.
     *
     * @param AcademicYear       $year      the course the date falls into
     * @param \DateTimeImmutable $date      the day
     * @param int                $slotIndex the period index within the day
     * @param int|null           $forPeople how many people must fit, or null if unknown
     *
     * @return list<Room> the candidate spaces, least disruptive first
     */
    public function candidatesAt(AcademicYear $year, \DateTimeImmutable $date, int $slotIndex, ?int $forPeople = null): array
    {
        return $this->at($year, $date, $slotIndex)->candidates($forPeople);
    }
}
