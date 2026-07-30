<?php

declare(strict_types=1);

namespace App\Space;

use App\Entity\Room;

/**
 * Where one displaced lesson ended up — the output of {@see RelocationSolver}.
 *
 * A null {@see $room} is a real answer, not a failure: with every room taken there IS nowhere to go, and
 * saying so is what lets the interface put that line in front of a person instead of hiding it.
 */
final readonly class Placement
{
    /**
     * @param Displacement $displacement what had to move
     * @param Room|null    $room         where it goes, or null when nothing fitted
     */
    public function __construct(
        public Displacement $displacement,
        public ?Room $room,
    ) {
    }

    /**
     * Whether the lesson found a room.
     *
     * @return bool true when it is placed
     */
    public function isResolved(): bool
    {
        return null !== $this->room;
    }
}
