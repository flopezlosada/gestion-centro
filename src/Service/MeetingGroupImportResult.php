<?php

declare(strict_types=1);

namespace App\Service;

/**
 * What an import of the Peñalara weekly meetings did, and what needs a human.
 */
final class MeetingGroupImportResult
{
    /**
     * @param list<string>                $created      groups created
     * @param list<string>                $updated      groups whose day, period or members were rewritten
     * @param list<string>                $unchanged    groups the file already agreed with
     * @param list<string>                $keptEdited   groups edited here that the import was told to leave alone
     * @param list<string>                $skipped      meetings not fixed to exactly one weekday period
     * @param array<string, list<string>> $unmatched    "Name (code)" of a Peñalara member matching nobody → the meetings they are in
     * @param list<string>                $missing      groups imported before that this file no longer has
     * @param list<string>                $noConvener   groups that repeat weekly but have nobody to convene them
     * @param bool                        $dryRun       whether nothing was written
     */
    public function __construct(
        public readonly array $created,
        public readonly array $updated,
        public readonly array $unchanged,
        public readonly array $keptEdited,
        public readonly array $skipped,
        public readonly array $unmatched,
        public readonly array $missing,
        public readonly array $noConvener,
        public readonly bool $dryRun,
    ) {
    }
}
