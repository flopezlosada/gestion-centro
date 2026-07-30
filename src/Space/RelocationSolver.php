<?php

declare(strict_types=1);

namespace App\Space;

use App\Entity\Room;
use App\Enum\ProposalStrategy;

/**
 * Decides where each displaced lesson goes. Pure: no database, no entity manager, no clock — the same
 * input always yields the same plan, which is what makes "three alternatives" reproducible and testable.
 *
 * Deliberately a greedy pass with an explainable comparator, not a constraint solver. With forty rooms
 * and six periods there is nothing to gain from an optimiser, and plenty to lose: nobody can look at
 * its output and say why the group ended up there, which is precisely what the equipo directivo has to
 * do before signing it.
 *
 * The order of the pass matters more than the comparator: the hardest lesson to place goes first (the
 * one with fewest candidate rooms), because placing an easy one first can leave the hard one homeless
 * when the reverse would have fitted both.
 */
final class RelocationSolver
{
    /**
     * Places every displacement, or leaves it unplaced when nothing fits.
     *
     * @param list<Displacement>        $displacements what has to move
     * @param array<string, list<Room>> $freeByMoment  candidate rooms, keyed by "Y-m-d|slot"
     * @param ProposalStrategy          $strategy      the criterion to follow
     *
     * @return list<Placement> one entry per displacement, in the input order
     */
    public function solve(array $displacements, array $freeByMoment, ProposalStrategy $strategy): array
    {
        // Room taken by an earlier placement, keyed by "moment|roomId": a room only holds one class.
        $taken = [];
        // Where each group has already been sent in this plan, for STABLE_ROOM.
        $groupHome = [];
        // Where everyone thrown out of the same room at the same moment went. A split group (desdoble)
        // or a whole-level session is several cells in ONE room: they must not be scattered.
        $together = [];

        $placements = [];
        foreach ($this->hardestFirst($displacements, $freeByMoment) as $index => $displacement) {
            $key = $displacement->moment().'|'.$displacement->togetherKey;
            $room = $together[$key] ?? null;

            if (null === $room) {
                $room = $this->pick($displacement, $this->candidatesFor($displacement, $freeByMoment, $taken), $strategy, $groupHome);
                if (null !== $room) {
                    $taken[$displacement->moment().'|'.$room->getId()] = true;
                    $together[$key] = $room;
                    if (ProposalStrategy::STABLE_ROOM === $strategy && null !== $displacement->groupNames) {
                        $groupHome[$displacement->groupNames] ??= $room;
                    }
                }
            }

            $placements[$index] = new Placement($displacement, $room);
        }

        // Back to the input order: the caller (and the printed document) reads it by date and period.
        ksort($placements);

        return array_values($placements);
    }

    /**
     * The displacements ordered by how few places they can go, hardest first, keeping the original index
     * so the result can be restored to input order.
     *
     * @param list<Displacement>        $displacements what has to move
     * @param array<string, list<Room>> $freeByMoment  candidate rooms by moment
     *
     * @return array<int, Displacement> the displacements, hardest first, keyed by original index
     */
    private function hardestFirst(array $displacements, array $freeByMoment): array
    {
        $ordered = $displacements;
        uasort($ordered, function (Displacement $a, Displacement $b) use ($freeByMoment): int {
            return [\count($this->candidatesFor($a, $freeByMoment, [])), $a->date->format('Y-m-d'), $a->slotIndex]
                <=> [\count($this->candidatesFor($b, $freeByMoment, [])), $b->date->format('Y-m-d'), $b->slotIndex];
        });

        return $ordered;
    }

    /**
     * The rooms this displacement could go to: free at that moment, not already taken by an earlier
     * placement, and big enough when the sizes are known.
     *
     * Size is measured in GROUPS ({@see \App\Enum\RoomSize}), the centre's own unit: a group thrown out
     * of an ordinary classroom does not fit in a desdoble room. The seat count filters too when both
     * rooms carry one, which is rare.
     *
     * Neither filters when it cannot. The centre classifies rooms by hand and every card starts blank,
     * so an unclassified room is offered rather than hidden — refusing to propose anything until the
     * catalogue is complete would be worse than proposing something a person then rejects.
     *
     * @param Displacement              $displacement what has to move
     * @param array<string, list<Room>> $freeByMoment candidate rooms by moment
     * @param array<string, true>       $taken        rooms already used, keyed by "moment|roomId"
     *
     * @return list<Room> the rooms it could go to
     */
    private function candidatesFor(Displacement $displacement, array $freeByMoment, array $taken): array
    {
        $size = $displacement->sizeNeeded;
        $seats = $displacement->seatsNeeded();
        $moment = $displacement->moment();

        return array_values(array_filter(
            $freeByMoment[$moment] ?? [],
            static fn (Room $room): bool => !isset($taken[$moment.'|'.$room->getId()])
                && (null === $size || null === $room->getSize() || $room->getSize()->fits($size))
                && (null === $seats || null === $room->getCapacity() || $room->getCapacity() >= $seats),
        ));
    }

    /**
     * Chooses one room out of the candidates according to the strategy.
     *
     * @param Displacement        $displacement what has to move
     * @param list<Room>          $candidates   the rooms it could go to
     * @param ProposalStrategy    $strategy     the criterion
     * @param array<string, Room> $groupHome    where each group already sits in this plan
     *
     * @return Room|null the chosen room, or null when there is nowhere to go
     */
    private function pick(Displacement $displacement, array $candidates, ProposalStrategy $strategy, array $groupHome): ?Room
    {
        if ([] === $candidates) {
            return null;
        }

        // STABLE_ROOM first tries the room this group already has in the plan: the whole point is that
        // the students walk to the same door every day.
        if (ProposalStrategy::STABLE_ROOM === $strategy && null !== $displacement->groupNames) {
            $home = $groupHome[$displacement->groupNames] ?? null;
            foreach ($candidates as $candidate) {
                if (null !== $home && $candidate->getId() === $home->getId()) {
                    return $candidate;
                }
            }
        }

        usort($candidates, fn (Room $a, Room $b): int => $this->rank($a, $displacement, $strategy) <=> $this->rank($b, $displacement, $strategy));

        return $candidates[0];
    }

    /**
     * The sort key of a candidate room: lower is better. The only difference between the strategies is
     * which term comes first — how specialised the room is, or how far it is from the original.
     *
     * @param Room             $room         the candidate
     * @param Displacement     $displacement what has to move
     * @param ProposalStrategy $strategy     the criterion
     *
     * @return list<int|string> the comparison key
     */
    private function rank(Room $room, Displacement $displacement, ProposalStrategy $strategy): array
    {
        $specialised = $room->getKind()->isSpecialised() ? 1 : 0;
        $distance = $this->distance($room, $displacement->originRoom);
        // Prefer the tightest room that still fits, so the big ones stay free for whoever needs them —
        // sending one group to the assembly hall because it happened to be nearest is a bad plan.
        $slack = null === $room->getSize()
            ? 9
            : $room->getSize()->groups() - ($displacement->sizeNeeded?->groups() ?? 0);

        return match ($strategy) {
            ProposalStrategy::PRESERVE_SPECIALISED => [$specialised * 10, $distance, $slack, $room->getCode()],
            default => [$distance, $specialised, $slack, $room->getCode()],
        };
    }

    /**
     * How far apart two rooms are, as a small penalty: 0 same floor of the same building, 1 another
     * floor, 2 another building, 3 when the centre has not said where either of them is.
     *
     * Crude on purpose — it is the difference between "next door" and "across the centre", which is all
     * anybody needs between periods. It stays at 3 for every room until somebody fills in the catalogue,
     * and then all candidates tie and the next term of the key decides.
     *
     * @param Room      $room   the candidate
     * @param Room|null $origin the room being left, or null for something that never had one (a workshop)
     *
     * @return int the penalty
     */
    private function distance(Room $room, ?Room $origin): int
    {
        // A workshop is not coming from anywhere, so no room is nearer than another for it.
        if (null === $origin) {
            return 0;
        }

        if (null === $room->getBuilding() && null === $origin->getBuilding() && null === $room->getFloor() && null === $origin->getFloor()) {
            return 3;
        }

        if ($room->getBuilding() !== $origin->getBuilding()) {
            return 2;
        }

        return $room->getFloor() === $origin->getFloor() ? 0 : 1;
    }
}
