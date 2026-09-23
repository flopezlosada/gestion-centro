<?php

declare(strict_types=1);

namespace App\Penalara;

use App\Enum\Weekday;

/**
 * One standing weekly meeting as the planificador declares it: its name, the day and period it is
 * fixed to, and the teachers it gathers (by their Peñalara employee code, the same one the timetable
 * import stores in {@see \App\Entity\User::$penalaraCode}).
 */
final class PenalaraMeetingDto
{
    /**
     * @param string       $name        the meeting's name in Peñalara, e.g. "TUTORES/AS 3º ESO"
     * @param Weekday      $weekday     the day it is fixed to
     * @param int          $slotIndex   the period of the day (0-based Peñalara {@code indice})
     * @param list<string> $memberCodes the Peñalara codes of the teachers it gathers
     */
    public function __construct(
        public readonly string $name,
        public readonly Weekday $weekday,
        public readonly int $slotIndex,
        public readonly array $memberCodes,
    ) {
    }
}
