<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The outcome of a roster load — or, when {@see $dryRun} is set, of the preview that runs before anybody
 * commits to it. Both the console command and the self-service screen report the same figures from here.
 *
 * {@see $skipped} matters as much as the counts: a line the reader dropped is a person who will not be
 * in the application, and finding that out in October because somebody never got a task is worse than
 * reading it here.
 */
final readonly class RosterImportResult
{
    /**
     * @param int          $rowCount    how many usable rows the CSV had
     * @param list<string> $created     names of the people who do not exist yet
     * @param int          $updated     how many existing people the run refreshes
     * @param list<string> $departments the departments the roster names
     * @param list<string> $skipped     one description per line that could not be read
     * @param bool         $dryRun      whether the run analysed only, writing nothing
     */
    public function __construct(
        public int $rowCount,
        public array $created,
        public int $updated,
        public array $departments,
        public array $skipped,
        public bool $dryRun,
    ) {
    }

    /**
     * Whether anything needs the reader's attention before committing: a line that could not be read, or
     * a roster that turned out to be empty.
     *
     * @return bool true when the report carries something to decide on
     */
    public function needsAttention(): bool
    {
        return [] !== $this->skipped || 0 === $this->rowCount;
    }
}
