<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Entity\User;

/**
 * A teacher available to cover a guardia at one period, with the two figures the equitable engine
 * ranks on: how many guardias they have already done at that period ({@see $slotLoad}) and in total
 * ({@see $totalLoad}). Whether they are an ordinary guardia or a collaborator (support duty, used
 * only when the guardias run out) is carried by {@see $collaborator}.
 *
 * A plain value object so {@see GuardiaAssigner} can be unit-tested without a database.
 */
final class GuardiaCandidate
{
    /**
     * @param User $teacher      the available teacher
     * @param bool $collaborator whether this is a collaborator (support) slot rather than a guardia
     * @param int  $slotLoad     confirmed guardias already done by this teacher at this period
     * @param int  $totalLoad    confirmed guardias already done by this teacher across all periods
     */
    public function __construct(
        public readonly User $teacher,
        public readonly bool $collaborator,
        public readonly int $slotLoad,
        public readonly int $totalLoad,
    ) {
    }
}
