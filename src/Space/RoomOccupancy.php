<?php

declare(strict_types=1);

namespace App\Space;

use App\Entity\AcademicYear;
use App\Entity\Room;
use App\Enum\Weekday;
use App\Repository\RoomRepository;
use App\Repository\ScheduleEntryRepository;

/**
 * Who is in which space at a given moment, and therefore which spaces are free.
 *
 * The single place that answers that question, because several screens need the same answer and must
 * not disagree: the "aulas libres" consultation, the guardia coordinator looking for a big room to
 * merge groups into, and — from the next phase on — the engine that proposes where a displaced group
 * can go.
 *
 * ONE LIMITATION, STATED UP FRONT: this reads the timetable, and the timetable only knows about
 * lessons. A meeting, a rehearsal or a talk booked verbally occupies a room that this service will
 * report as free. That is why the screen is called "aulas libres SEGÚN EL HORARIO" and why a room can
 * be marked non-assignable ({@see Room::isAssignable()}) — the honest answer is "the timetable puts
 * nobody here", not "this room is free".
 *
 * The date, not the weekday, is the input: a later phase adds the approved space plans that alter a
 * concrete day, and every caller already asking "on this date" will pick them up without changing.
 */
final class RoomOccupancy
{
    public function __construct(
        private readonly RoomRepository $rooms,
        private readonly ScheduleEntryRepository $schedule,
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

        // Several cells can share one room in the same period: a split group (desdoble) or a whole-level
        // activity is listed once per group. Fold them into one occupation per room, or the screen would
        // report the same room twice and the free list would still be right by accident.
        $occupations = [];
        foreach ($this->schedule->occupancyAt($year, $weekday, $slotIndex) as $entry) {
            $room = $entry->getRoom();
            if (null === $room || null === $room->getId()) {
                continue;
            }

            $occupations[$room->getId()][] = $entry;
        }

        $free = [];
        $occupied = [];
        foreach ($this->rooms->findActive() as $room) {
            $entries = $occupations[$room->getId()] ?? [];
            if ([] === $entries) {
                $free[] = $room;
                continue;
            }

            $occupied[] = new RoomOccupation($room, $entries);
        }

        return new RoomAvailability($free, $occupied);
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
