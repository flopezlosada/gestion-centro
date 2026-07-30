<?php

declare(strict_types=1);

namespace App\Space;

use App\Entity\Room;
use App\Entity\ScheduleEntry;
use App\Entity\SpacePlanAssignment;

/**
 * One occupied space at one period: the room and whatever is sitting in it.
 *
 * Two things can be: the ordinary timetable's lessons ({@see $entries}) and the lines of an approved
 * space plan ({@see $lines}) — a relocated class or an activity. Both are told the same way, because to
 * whoever is looking for a free room the difference does not matter; what matters is that the room is
 * taken. {@see isPlanned()} lets a screen mark the ones that are there because of a plan.
 *
 * Several occupants share a room when a group is split (desdoble) or a whole level meets at once, so
 * each side is a list, not a single class.
 */
final readonly class RoomOccupation
{
    /**
     * @param Room                      $room    the occupied space
     * @param list<ScheduleEntry>       $entries the ordinary lessons in it that period
     * @param list<SpacePlanAssignment> $lines   the approved plan lines that put something in it
     */
    public function __construct(
        public Room $room,
        public array $entries = [],
        public array $lines = [],
    ) {
    }

    /**
     * Whether an approved plan is what puts something here — a relocated class or an activity, rather
     * than the ordinary timetable.
     *
     * @return bool true when at least one plan line occupies this room
     */
    public function isPlanned(): bool
    {
        return [] !== $this->lines;
    }

    /**
     * The groups in the room, ", "-separated and de-duplicated.
     *
     * @return string the group names, or "—" when nothing names one
     */
    public function groups(): string
    {
        return $this->join([
            ...array_map(static fn (ScheduleEntry $e): ?string => $e->getGroupName(), $this->entries),
            ...array_map(static fn (SpacePlanAssignment $a): ?string => $a->getGroupNames(), $this->lines),
        ]);
    }

    /**
     * The teachers in the room, ", "-separated and de-duplicated.
     *
     * @return string the teacher names, or "—" if there are none
     */
    public function teachers(): string
    {
        return $this->join([
            ...array_map(static fn (ScheduleEntry $e): string => $e->getTeacher()->getFullName(), $this->entries),
            ...array_map(static fn (SpacePlanAssignment $a): ?string => $a->getTeacher()?->getFullName(), $this->lines),
        ]);
    }

    /**
     * What is being done in the room: the subjects of the ordinary lessons and the titles of the
     * activities, ", "-separated and de-duplicated.
     *
     * @return string the subjects and activity titles, or "—" when nothing names one
     */
    public function subjects(): string
    {
        return $this->join([
            ...array_map(static fn (ScheduleEntry $e): ?string => $e->getSubjectName(), $this->entries),
            ...array_map(static fn (SpacePlanAssignment $a): ?string => $a->getActivityTitle() ?? $a->getSubjectName(), $this->lines),
        ]);
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
