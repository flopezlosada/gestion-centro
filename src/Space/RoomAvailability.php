<?php

declare(strict_types=1);

namespace App\Space;

use App\Entity\Room;
use App\Enum\RoomSize;

/**
 * The answer {@see RoomOccupancy::at()} gives: which catalogued spaces are free at that period and
 * which are taken, by whom.
 */
final readonly class RoomAvailability
{
    /**
     * @param list<Room>           $free     the spaces the timetable puts nobody in
     * @param list<RoomOccupation> $occupied the spaces in use, with their occupiers
     */
    public function __construct(
        public array $free,
        public array $occupied,
    ) {
    }

    /**
     * The free spaces a group could actually be sent to (free is not the same as usable: the courts
     * are free most of the day).
     *
     * @return list<Room> the free and assignable spaces
     */
    public function assignable(): array
    {
        return array_values(array_filter($this->free, static fn (Room $r): bool => $r->isAssignable()));
    }

    /**
     * The spaces to propose for a group that has to go somewhere: free, assignable, and big enough for
     * the number of GROUPS asked for. Ordered so the least disruptive choice comes first — ordinary
     * classrooms before specialised ones, and the tightest fit before the roomiest, so the assembly hall
     * stays free for whoever really needs it.
     *
     * Size only filters when it can: every card starts unclassified, so a room of unknown size is
     * offered (saying so) rather than hidden. Dropping candidates for missing data would be worse than
     * showing one a person then rejects.
     *
     * @param int|null $forGroups how many whole groups must fit, or null if it does not matter
     *
     * @return list<Room> the candidate spaces, least disruptive first
     */
    public function candidates(?int $forGroups = null): array
    {
        $candidates = array_values(array_filter(
            $this->assignable(),
            static fn (Room $r): bool => null === $forGroups || null === $r->getSize() || $r->getSize()->groups() >= $forGroups,
        ));

        usort($candidates, static fn (Room $a, Room $b): int => [$a->getKind()->isSpecialised(), self::groupsOf($a), $a->getCode()]
            <=> [$b->getKind()->isSpecialised(), self::groupsOf($b), $b->getCode()]);

        return $candidates;
    }

    /**
     * How many groups a room holds, for ordering. An unclassified room sorts last: it may well be fine,
     * but a room somebody has confirmed is better than a guess.
     *
     * @param Room $room the room
     *
     * @return int its group capacity, or a high value when unknown
     */
    private static function groupsOf(Room $room): int
    {
        return $room->getSize()?->groups() ?? 9;
    }

    /**
     * The free spaces {@see candidates()} leaves out: a court no group can be sent to, or a room too
     * small for the size asked for. Listed apart rather than hidden — "free" and "usable" are different
     * answers, and hiding the difference is what makes a person distrust the screen.
     *
     * @param int|null $forGroups the number of groups asked for, matching the call to {@see candidates()}
     *
     * @return list<Room> the free spaces not being proposed
     */
    public function otherFree(?int $forGroups = null): array
    {
        $offered = array_flip(array_map(static fn (Room $r): ?int => $r->getId(), $this->candidates($forGroups)));

        return array_values(array_filter($this->free, static fn (Room $r): bool => !isset($offered[$r->getId()])));
    }
}
