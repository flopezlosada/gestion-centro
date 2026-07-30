<?php

declare(strict_types=1);

namespace App\Space;

use App\Entity\Room;
use App\Entity\ScheduleEntry;

/**
 * One occupied space at one period: the room and the lesson(s) sitting in it. Several lessons share a
 * room when a group is split or a whole level meets at once, so the occupier is a list, not a single
 * class.
 */
final readonly class RoomOccupation
{
    /**
     * @param Room                $room    the occupied space
     * @param list<ScheduleEntry> $entries the lessons in it that period
     */
    public function __construct(
        public Room $room,
        public array $entries,
    ) {
    }

    /**
     * The groups in the room, ", "-separated and de-duplicated.
     *
     * @return string the group names, or "—" when the timetable names none
     */
    public function groups(): string
    {
        return $this->join(array_map(static fn (ScheduleEntry $e): ?string => $e->getGroupName(), $this->entries));
    }

    /**
     * The teachers in the room, ", "-separated and de-duplicated.
     *
     * @return string the teacher names, or "—" if there are none
     */
    public function teachers(): string
    {
        return $this->join(array_map(static fn (ScheduleEntry $e): string => $e->getTeacher()->getFullName(), $this->entries));
    }

    /**
     * The subjects taught in the room, ", "-separated and de-duplicated.
     *
     * @return string the subject names, or "—" when the timetable names none
     */
    public function subjects(): string
    {
        return $this->join(array_map(static fn (ScheduleEntry $e): ?string => $e->getSubjectName(), $this->entries));
    }

    /**
     * Folds a list of names into one readable value: non-empty, distinct, ", "-separated.
     *
     * @param list<string|null> $values the raw names
     *
     * @return string the folded value, or an em dash when nothing is left
     */
    private function join(array $values): string
    {
        $kept = array_unique(array_filter(array_map(
            static fn (?string $v): string => trim((string) $v),
            $values,
        ), static fn (string $v): bool => '' !== $v));

        return [] === $kept ? '—' : implode(', ', $kept);
    }
}
