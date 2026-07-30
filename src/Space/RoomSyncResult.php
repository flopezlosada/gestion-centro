<?php

declare(strict_types=1);

namespace App\Space;

/**
 * What one run of {@see RoomSynchroniser} did: which space cards it had to create and how many
 * timetable cells it linked to a card. Reported to the person who ran the import (or the command), so
 * "the catalogue grew by three rooms" is visible rather than silent.
 */
final readonly class RoomSyncResult
{
    /**
     * @param list<string> $createdCodes the codes of the space cards created, alphabetically
     * @param int          $linkedCells  how many timetable cells were linked to their space
     */
    public function __construct(
        public array $createdCodes,
        public int $linkedCells,
    ) {
    }

    /**
     * Whether the run changed anything at all — a re-import of an unchanged timetable does not.
     *
     * @return bool true when something was created or linked
     */
    public function isEmpty(): bool
    {
        return [] === $this->createdCodes && 0 === $this->linkedCells;
    }
}
