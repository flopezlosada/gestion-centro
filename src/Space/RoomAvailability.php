<?php

declare(strict_types=1);

namespace App\Space;

use App\Entity\Room;

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
     * The spaces to propose for a displaced group: free, assignable, and big enough when both the
     * room's capacity and the required size are known. Ordered so the least disruptive choice comes
     * first — ordinary classrooms before specialised ones, and within each band the biggest first.
     *
     * Capacity only filters when it can: the export carries none and the centre fills it in by hand, so
     * a room of unknown capacity is offered (saying so) rather than hidden. Dropping candidates for
     * missing data would be worse than showing one that may not fit.
     *
     * @param int|null $forPeople how many people must fit, or null if unknown
     *
     * @return list<Room> the candidate spaces, least disruptive first
     */
    public function candidates(?int $forPeople = null): array
    {
        $candidates = array_values(array_filter(
            $this->assignable(),
            static fn (Room $r): bool => null === $forPeople || null === $r->getCapacity() || $r->getCapacity() >= $forPeople,
        ));

        usort($candidates, static fn (Room $a, Room $b): int => [$a->getKind()->isSpecialised(), -($a->getCapacity() ?? 0), $a->getCode()]
            <=> [$b->getKind()->isSpecialised(), -($b->getCapacity() ?? 0), $b->getCode()]);

        return $candidates;
    }

    /**
     * The free spaces {@see candidates()} leaves out: a court no group can be sent to, or a room too
     * small for the size asked for. Listed apart rather than hidden — "free" and "usable" are different
     * answers, and hiding the difference is what makes a person distrust the screen.
     *
     * @param int|null $forPeople the size asked for, matching the call to {@see candidates()}
     *
     * @return list<Room> the free spaces not being proposed
     */
    public function otherFree(?int $forPeople = null): array
    {
        $offered = array_flip(array_map(static fn (Room $r): ?int => $r->getId(), $this->candidates($forPeople)));

        return array_values(array_filter($this->free, static fn (Room $r): bool => !isset($offered[$r->getId()])));
    }
}
