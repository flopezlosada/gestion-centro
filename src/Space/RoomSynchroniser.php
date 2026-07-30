<?php

declare(strict_types=1);

namespace App\Space;

use App\Entity\Room;
use App\Repository\RoomRepository;
use App\Repository\ScheduleEntryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Keeps the space catalogue in step with the imported timetable: every room name the timetable uses
 * gets a card ({@see Room}), and every timetable cell gets a foreign key to it.
 *
 * Why this is a separate service and not a step inside the importer: discovering spaces is a concern of
 * the space module, not of reading Peñalara, and it has to be runnable on its own — over a database
 * whose timetable was imported long before this module existed (staging), or after a person renames a
 * code by hand. So the importer calls it as its last step and the {@code app:sync-rooms} command calls
 * the very same thing.
 *
 * It is deliberately one-way and additive: it CREATES the cards it is missing and never updates or
 * deletes one. A card is centre-owned data (capacity, type, whether a group may be sent there); a
 * re-import must not undo somebody's afternoon of filling that in. A room that disappears from the
 * timetable is left alone too — history still points at it.
 *
 * The single exception, and it is not one really: {@see \App\Entity\Room::$observedGroups} is refreshed
 * here every time. That column is not the centre's answer but the timetable's own evidence of how many
 * groups fit, so recomputing it is the point of running this — and it cannot overwrite anybody's work
 * because nobody can edit it.
 *
 * Idempotent: running it twice over an unchanged timetable creates nothing and links nothing.
 */
final class RoomSynchroniser
{
    public function __construct(
        private readonly RoomRepository $rooms,
        private readonly ScheduleEntryRepository $schedule,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Creates the missing room cards, links every unlinked timetable cell to its card, and refreshes the
     * size evidence of every card.
     *
     * The three halves run in one transaction: a half-applied sync would leave cells whose room exists but
     * is not pointed at, which reads as "that room is free" everywhere in the module.
     *
     * @return RoomSyncResult how many cards were created, how many cells were linked and how many sizes moved
     */
    public function sync(): RoomSyncResult
    {
        $created = [];
        $linked = 0;
        $resized = 0;

        $this->em->wrapInTransaction(function () use (&$created, &$linked, &$resized): void {
            // One read of what is unlinked drives both halves: which cards are missing and which rows
            // each card claims. Matching happens here, in PHP, on the normalised code — never in SQL,
            // where the answer would depend on the database's collation.
            $idsByCode = [];
            foreach ($this->schedule->unlinkedRoomCells() as $cell) {
                $code = Room::normaliseCode($cell['roomName']);
                if ('' !== $code) {
                    $idsByCode[$code][] = $cell['id'];
                }
            }

            // A stub card for each room the catalogue lacks. Its name starts as the code: only a person
            // can say that "2IN5" is "Aula de Inglés 5".
            $byCode = $this->rooms->indexedByCode();
            foreach (array_keys($idsByCode) as $key) {
                // PHP turns a numeric string array key into an int, and a room may well be called
                // "101": cast back before it reaches a string setter.
                $code = (string) $key;
                if (isset($byCode[$code])) {
                    continue;
                }

                $room = (new Room())->setCode($code)->setName($code);
                $this->em->persist($room);
                $byCode[$code] = $room;
                $created[] = $code;
            }
            // Flush before linking: the link needs the new cards to have ids.
            $this->em->flush();

            foreach ($idsByCode as $key => $ids) {
                $linked += $this->schedule->linkCells($byCode[(string) $key], $ids);
            }

            // Last, and unconditionally: the evidence is read back from the cells, so it has to be
            // recomputed after linking them — and also when nothing was linked at all, because a
            // re-import can change which groups share a room without changing any room name.
            $resized = $this->refreshObservedSizes();
        });

        sort($created);

        return new RoomSyncResult($created, $linked, $resized);
    }

    /**
     * Writes back how many groups the timetable puts in each space at once
     * ({@see \App\Entity\Room::$observedGroups}), so a card nobody has classified still knows whether it
     * is a big room.
     *
     * Every active card is visited, not only the ones with evidence: a room that stops appearing in the
     * timetable has to LOSE its observed size rather than keep a figure no cell backs any more.
     *
     * @return int how many cards changed
     */
    private function refreshObservedSizes(): int
    {
        $observed = $this->schedule->observedGroupsByRoom();
        $changed = 0;

        foreach ($this->rooms->findAllOrdered() as $room) {
            $groups = $observed[(int) $room->getId()] ?? null;
            if ($groups === $room->getObservedGroups()) {
                continue;
            }

            $room->setObservedGroups($groups);
            ++$changed;
        }
        $this->em->flush();

        return $changed;
    }

    /**
     * The timetable cells that name a room no card covers — always empty right after {@see sync()},
     * and the number the import screen shows so a broken link never goes unnoticed.
     *
     * @return int how many cells still have no catalogued space
     */
    public function unlinkedCells(): int
    {
        return $this->schedule->countCellsWithoutRoom();
    }
}
