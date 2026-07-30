<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Entity\AcademicYear;
use App\Entity\ScheduleEntry;
use App\Enum\Weekday;
use App\Repository\ScheduleEntryRepository;

/**
 * Which rooms are free at a given hour, and which of them are big — the "documento de aulas libres" the
 * centre asks for so that, when there are more absences than guardia teachers, several groups can be
 * sent to one large room instead of leaving them scattered and unattended.
 *
 * Derived from the imported timetable, not from a document: a room is taken at an hour if somebody is
 * teaching in it then, and a room is big if the timetable has been seen putting several groups in it at
 * once (see {@see ScheduleEntryRepository::observedRoomCapacity()}). That is deliberately evidence and
 * not configuration — a capacity typed in by hand goes stale the first time a room changes use, and the
 * centre would have to maintain it. The honest limit: a room nobody ever teaches in is invisible.
 *
 * Rooms already taken are NOT hidden. Freeing up the library or the assembly hall by moving the class
 * that is in it is precisely one of the centre's requirements, so the caller gets every room with its
 * state and who is in it, and decides.
 */
final class FreeRooms
{
    public function __construct(
        private readonly ScheduleEntryRepository $schedule,
    ) {
    }

    /**
     * Every room at one period, biggest first, each with whether it is taken and by whom. The order is
     * what makes the list useful: whoever needs to park three groups somewhere reads the top of it.
     *
     * @param AcademicYear $year      the course whose timetable to read
     * @param Weekday      $weekday   the weekday
     * @param int          $slotIndex the period index within the day
     *
     * @return list<array{room: string, capacity: int, free: bool, classes: list<array{teacher: \App\Entity\User, group: ?string, subject: ?string}>}> the rooms, biggest first then by name
     */
    public function atSlot(AcademicYear $year, Weekday $weekday, int $slotIndex): array
    {
        $capacity = $this->schedule->observedRoomCapacity($year);

        $classes = [];
        foreach ($this->schedule->lectiveEntriesWithRoomAt($year, $weekday, $slotIndex) as $entry) {
            $classes[(string) $entry->getRoomName()][] = [
                'teacher' => $entry->getTeacher(),
                'group' => $entry->getGroupName(),
                'subject' => $entry->getSubjectName(),
            ];
        }

        $rooms = [];
        foreach ($this->schedule->distinctRooms($year) as $room) {
            $rooms[] = [
                'room' => $room,
                'capacity' => $capacity[$room] ?? 1,
                'free' => !isset($classes[$room]),
                'classes' => $classes[$room] ?? [],
            ];
        }
        usort($rooms, static fn (array $a, array $b): int => $b['capacity'] <=> $a['capacity'] ?: strcasecmp($a['room'], $b['room']));

        return $rooms;
    }

    /**
     * The free rooms of a whole weekday, period by period: period index → rooms, biggest first. This is
     * the printable sheet, and it is one query for the day rather than one per period.
     *
     * @param AcademicYear $year    the course whose timetable to read
     * @param Weekday      $weekday the weekday
     * @param list<int>    $slots   the period indices to report, in the order to report them
     *
     * @return array<int, list<array{room: string, capacity: int}>> period index → the free rooms, biggest first
     */
    public function freeBySlot(AcademicYear $year, Weekday $weekday, array $slots): array
    {
        $capacity = $this->schedule->observedRoomCapacity($year);
        $allRooms = $this->schedule->distinctRooms($year);
        $occupied = $this->schedule->occupiedRoomsBySlot($year, $weekday);

        $free = [];
        foreach ($slots as $slotIndex) {
            $taken = $occupied[$slotIndex] ?? [];
            $rooms = [];
            foreach ($allRooms as $room) {
                if (!\in_array($room, $taken, true)) {
                    $rooms[] = ['room' => $room, 'capacity' => $capacity[$room] ?? 1];
                }
            }
            usort($rooms, static fn (array $a, array $b): int => $b['capacity'] <=> $a['capacity'] ?: strcasecmp($a['room'], $b['room']));
            $free[$slotIndex] = $rooms;
        }

        return $free;
    }

    /**
     * The classes a grouping would displace by taking over a room at one period — who is teaching there
     * and what. Empty when the room is free, which is the ordinary case.
     *
     * Read live from the timetable rather than stored on the grouping: who was in the room is always
     * re-derivable, and a copy would be one more thing to keep in step with a re-import.
     *
     * @param AcademicYear $year      the course whose timetable to read
     * @param Weekday      $weekday   the weekday
     * @param int          $slotIndex the period index within the day
     * @param string       $room      the room short name being taken over
     *
     * @return list<ScheduleEntry> the classes in that room then
     */
    public function classesIn(AcademicYear $year, Weekday $weekday, int $slotIndex, string $room): array
    {
        return array_values(array_filter(
            $this->schedule->lectiveEntriesWithRoomAt($year, $weekday, $slotIndex),
            static fn (ScheduleEntry $e): bool => 0 === strcasecmp((string) $e->getRoomName(), $room),
        ));
    }
}
