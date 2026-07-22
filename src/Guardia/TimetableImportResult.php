<?php

declare(strict_types=1);

namespace App\Guardia;

/**
 * The outcome of a timetable import, so both the console command and the self-service admin screen can
 * report the same figures: how many cells were built, how many were guardia/collaborator duties, how
 * many teachers were reconciled, and — the part that needs action — the teachers nobody could be
 * matched to (so they can be given an account and the import re-run).
 *
 * Immutable value object; {@see TimetableImporter} produces it.
 */
final class TimetableImportResult
{
    /**
     * @param int                   $entryCount   total schedule cells built for the matched teachers
     * @param int                   $guardiaCount how many of those are guardia/collaborator duties
     * @param int                   $matchedCount how many teachers were reconciled to a user
     * @param array<string, string> $unmatched    Peñalara code → name of teachers matched to nobody
     * @param bool                  $dryRun       whether the run analysed only, writing nothing
     */
    public function __construct(
        public readonly int $entryCount,
        public readonly int $guardiaCount,
        public readonly int $matchedCount,
        public readonly array $unmatched,
        public readonly bool $dryRun,
    ) {
    }
}
