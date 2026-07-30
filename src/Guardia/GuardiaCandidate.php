<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Entity\User;
use App\Enum\GuardiaDutyBand;

/**
 * A teacher who can cover a guardia at one period, with everything the equitable engine ranks on:
 * which band they come from ({@see $band} — the ordinary rota, a collaborator or hand-added support),
 * how many groups they are ALREADY covering at this very date and period ({@see $hereLoad}) and their
 * historical balance at this period ({@see $slotLoad}) and overall ({@see $totalLoad}).
 *
 * {@see $hereLoad} and {@see $slotLoad} are easy to confuse and mean opposite things: the first is
 * "today, right now, is this person already busy?" (0 means free; above 0, assigning them again is
 * doubling up), the second is "over the whole course, how much have they carried at this hour?" —
 * the fairness measure.
 *
 * A plain value object so {@see GuardiaAssigner} can be unit-tested without a database.
 */
final class GuardiaCandidate
{
    /**
     * @param User             $teacher   the available teacher
     * @param GuardiaDutyBand  $band      which band offers them (rota, collaborator, hand-added support)
     * @param int              $slotLoad  confirmed guardias already done by this teacher at this period
     * @param int              $totalLoad confirmed guardias already done by this teacher across all periods
     * @param int              $hereLoad  groups this teacher is already covering at this date and period
     */
    public function __construct(
        public readonly User $teacher,
        public readonly GuardiaDutyBand $band,
        public readonly int $slotLoad,
        public readonly int $totalLoad,
        public readonly int $hereLoad = 0,
    ) {
    }

    /**
     * Whether taking this candidate means giving them a second (or third…) group in the same period.
     *
     * @return bool true when they are already covering something at this date and period
     */
    public function isDoublingUp(): bool
    {
        return $this->hereLoad > 0;
    }
}
