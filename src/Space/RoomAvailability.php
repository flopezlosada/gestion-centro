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
     * Only a size the centre CONFIRMED ({@see Room::getSize()}) rules a room out. The size the timetable
     * suggests ({@see Room::observedSize()}) is a lower bound — "two groups have been in here" proves two
     * fit, "one group" proves nothing about the second — so it orders the list but never shortens it.
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
     * The free spaces biggest first — the other question, and the opposite order: not "where does this
     * group go" but "which big rooms are free", which is what the guardia coordinator asks when several
     * groups have to be minded together and the sheet for the noticeboard has to answer at a glance.
     *
     * Assignable spaces only, so a printed sheet does not read as if the courts were classrooms; whoever
     * needs to offer those anyway ("three groups in the gym for one period") gets them from
     * {@see otherFree()} and can say what they are. Rooms of unknown size ARE included, last, for the same
     * reason {@see candidates()} keeps them.
     *
     * @return list<Room> the free and assignable spaces, roomiest first then by code
     */
    public function largestFirst(): array
    {
        $rooms = $this->assignable();
        usort($rooms, self::bySizeDescending(...));

        return $rooms;
    }

    /**
     * The occupied spaces a group could still be sent to, roomiest first — because taking over the
     * library or the assembly hall and moving whoever is in it is not an edge case, it is what the centre
     * asked for. Whoever is in each one comes with it, so the screen can say who will be displaced.
     *
     * Spaces no lesson can be held in (the courts) are left out even when occupied: they are of no use
     * for minding groups whether they are free or not.
     *
     * @return list<RoomOccupation> the occupied assignable spaces, roomiest first then by code
     */
    public function occupiedLargestFirst(): array
    {
        $occupied = array_values(array_filter($this->occupied, static fn (RoomOccupation $o): bool => $o->room->isAssignable()));
        usort($occupied, static fn (RoomOccupation $a, RoomOccupation $b): int => self::bySizeDescending($a->room, $b->room));

        return $occupied;
    }

    /**
     * Orders two spaces roomiest first, on four keys: known size before unknown, then most groups first,
     * then what the centre CONFIRMED before what the timetable merely suggests, then code. The unknown ones
     * cannot ride along with the big rooms just because "unknown" happens to be a high number — the top of
     * this list is where somebody looks for a room that holds three groups.
     *
     * Confirmed before observed matters at the top of each size: "the centre says three groups fit" and
     * "three groups have happened to be in here" are the same number and not the same promise, so the
     * answer somebody can rely on is offered first.
     *
     * @param Room $a the first space
     * @param Room $b the second space
     *
     * @return int the comparison
     */
    private static function bySizeDescending(Room $a, Room $b): int
    {
        $groupsA = $a->effectiveSize()?->groups();
        $groupsB = $b->effectiveSize()?->groups();

        return [null === $groupsA, -($groupsA ?? 0), !$a->isSizeConfirmed(), $a->getCode()]
            <=> [null === $groupsB, -($groupsB ?? 0), !$b->isSizeConfirmed(), $b->getCode()];
    }

    /**
     * How many groups a room holds, for ordering: what the centre confirmed, and failing that what the
     * timetable has shown. A room neither a person nor the timetable says anything about sorts last — it
     * may well be fine, but an answer is better than a blank.
     *
     * @param Room $room the room
     *
     * @return int its group capacity, or a high value when unknown
     */
    private static function groupsOf(Room $room): int
    {
        return $room->effectiveSize()?->groups() ?? 9;
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
