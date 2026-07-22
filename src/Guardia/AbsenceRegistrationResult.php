<?php

declare(strict_types=1);

namespace App\Guardia;

/**
 * Outcome of registering a teacher's absence across one or more periods: which periods became a
 * cover, and how many were skipped because the teacher was free then or already had a cover. Lets
 * the caller give a precise summary ("3 guardias generadas, 2 horas libres omitidas").
 */
final class AbsenceRegistrationResult
{
    /**
     * @param list<int> $createdSlots     period indices for which a cover was created
     * @param int       $skippedFree      periods skipped because the teacher had no class then
     * @param int       $skippedExisting  periods skipped because a cover already existed
     */
    public function __construct(
        public readonly array $createdSlots,
        public readonly int $skippedFree,
        public readonly int $skippedExisting,
    ) {
    }

    /**
     * How many covers were created.
     *
     * @return int the number of periods turned into a cover
     */
    public function createdCount(): int
    {
        return \count($this->createdSlots);
    }
}
