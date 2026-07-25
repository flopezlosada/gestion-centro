<?php

declare(strict_types=1);

namespace App\Guardia;

/**
 * The outcome of a timetable import — or, when {@see $dryRun} is set, of the preview that runs before
 * anyone commits to it. Both the console command and the self-service admin screen report the same
 * figures from here: how many cells were built, how many were guardia/collaborator duties, how many
 * teachers were reconciled, what the import replaced, and the three things that need a human decision:
 * teachers nobody could be matched to, hand-marked guardias that were respected, and teachers who keep
 * a timetable this export no longer carries.
 *
 * Immutable value object; {@see TimetableImporter} produces it.
 */
final class TimetableImportResult
{
    /**
     * @param int                   $entryCount    total schedule cells built for the matched teachers
     * @param int                   $guardiaCount  how many of those are guardia/collaborator duties
     * @param int                   $matchedCount  how many teachers were reconciled to a user
     * @param array<string, string> $unmatched     Peñalara code → name of teachers matched to nobody
     * @param bool                  $dryRun        whether the run analysed only, writing nothing
     * @param int                   $replacedCount imported cells those teachers already had, which this run replaces
     * @param int                   $keptManual    export cells skipped because a hand-marked guardia holds that period
     * @param int                   $droppedManual hand-marked guardias removed because the new timetable teaches then
     * @param list<string>          $stale         teachers holding a timetable in this course that the export does not carry
     */
    public function __construct(
        public readonly int $entryCount,
        public readonly int $guardiaCount,
        public readonly int $matchedCount,
        public readonly array $unmatched,
        public readonly bool $dryRun,
        public readonly int $replacedCount = 0,
        public readonly int $keptManual = 0,
        public readonly int $droppedManual = 0,
        public readonly array $stale = [],
    ) {
    }

    /**
     * Whether anything at all needs the reader's attention: someone unmatched, a hand-marked guardia
     * touched, or a teacher left behind. Drives whether the preview shows warnings or just a green tick.
     *
     * @return bool true when the report carries something to decide on
     */
    public function needsAttention(): bool
    {
        return [] !== $this->unmatched || [] !== $this->stale || $this->keptManual > 0 || $this->droppedManual > 0;
    }
}
