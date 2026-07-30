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
            $key = $displacement->moment().'|'.$displacement->originRoom->getId();
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
     * placement, and big enough when both sizes are known.
     *
     * Capacity only filters when it can. The centre fills capacities in by hand and most cards start
     * empty, so an unknown capacity offers the room rather than hiding it — with no enrolment data,
     * refusing to propose anything would be worse than proposing something a person then rejects.
     *
     * @param Displacement              $displacement what has to move
     * @param array<string, list<Room>> $freeByMoment candidate rooms by moment
     * @param array<string, true>       $taken        rooms already used, keyed by "moment|roomId"
     *
     * @return list<Room> the rooms it could go to
     */
    private function candidatesFor(Displacement $displacement, array $freeByMoment, array $taken): array
    {
        $needed = $displacement->seatsNeeded();
        $moment = $displacement->moment();

        return array_values(array_filter(
            $freeByMoment[$moment] ?? [],
            static fn (Room $room): bool => !isset($taken[$moment.'|'.$room->getId()])
                && (null === $needed || null === $room->getCapacity() || $room->getCapacity() >= $needed),
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
        // Prefer the tightest room that still fits, so the big ones stay available for whoever needs them.
        $slack = null === $room->getCapacity() ? 999 : $room->getCapacity() - ($displacement->seatsNeeded() ?? 0);

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
     * @param Room $room   the candidate
     * @param Room $origin the room being left
     *
     * @return int the penalty
     */
    private function distance(Room $room, Room $origin): int
    {
        if (null === $room->getBuilding() && null === $origin->getBuilding() && null === $room->getFloor() && null === $origin->getFloor()) {
            return 3;
        }

        if ($room->getBuilding() !== $origin->getBuilding()) {
            return 2;
        }

        return $room->getFloor() === $origin->getFloor() ? 0 : 1;
    }
}
