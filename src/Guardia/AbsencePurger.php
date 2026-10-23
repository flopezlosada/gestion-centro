<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Enum\AbsenceRetention;
use App\Service\FileUploader;
use App\Util\GroupCode;
use Doctrine\DBAL\Connection;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

/**
 * Forgets who was away once the plazo the centre keeps it for is over ({@see AbsenceRetention}).
 *
 * The privacy notice promises it, and only the administrative staff need it, to ask each teacher for
 * their justificante and record it in RAICES. Past the plazo, every day before it loses:
 *
 *  - its absences, and with them its covers, which become {@see \App\Entity\ArchivedGuardiaCover}: who did
 *    each guardia still counts for the fair split and the statistics, whom it covered is gone, and so are
 *    the group, the room, the subject, the task left and what happened;
 *  - its groupings, which say where those groups were;
 *  - the task documents of its covers, from disk: they are a group's work for a teacher's absence;
 *  - what the copy orders for those covers say: the summary (group, room, day, period), the notes, who
 *    placed it (often the absent teacher leaving the task) and their documents, from disk and by name. The
 *    order stays, counted, with its copies. {@see PastDocumentPurger} would get to the documents on its
 *    own, but by when the order was placed once it no longer points at its guardia, so an unsent one
 *    would keep them some days past the plazo;
 *  - the guardia notices, which name the absent teacher or the group ("Cubres a …");
 *  - the activity trail of all the above, including that of what was deleted by hand.
 *
 * What survives is copied first and on purpose coarser: the periods each group spent without its teacher,
 * by month ({@see \App\Entity\GuardiaGroupMonth}), and which bank tasks each group has done, without the
 * day ({@see \App\Entity\GuardiaBankGroupUse}).
 *
 * All of it in SQL and in bulk, never through the ORM: {@see \App\EventSubscriber\EntityAuditSubscriber}
 * would write a "deleted" entry per row with everything it held, which is exactly what is being forgotten.
 * One transaction, so a failure leaves the day whole for the next run; the files go only after it commits,
 * so a rollback never leaves a cover pointing at a document already gone.
 *
 * Mails and push notices already sent are out of reach: they live in each person's inbox and device.
 */
final class AbsencePurger
{
    /**
     * What a copy order for a forgotten guardia says it was for instead.
     */
    public const string FORGOTTEN_ORDER_CONTEXT = 'Guardia (datos borrados al pasar el plazo)';

    /**
     * The audited entities of a day and where each keeps its date: their trail is deleted with them.
     */
    private const array AUDITED = [
        'GuardiaCover' => ['guardia_cover', 'cover_date'],
        'Absence' => ['guardia_absence', 'absence_date'],
        'GuardiaGrouping' => ['guardia_grouping', 'grouping_date'],
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly FileUploader $uploader,
    ) {
    }

    /**
     * Forgets every absence before the plazo, counting back from a day.
     *
     * @param AbsenceRetention   $retention the plazo the centre keeps absences for
     * @param \DateTimeImmutable $today     the day the plazo counts back from
     *
     * @return array{absences: int, covers: int, unremoved: list<string>} how many absences were deleted, how
     *                                                                   many covers archived, and the task and
     *                                                                   copy order documents that could not be
     *                                                                   deleted from disk
     */
    public function purge(AbsenceRetention $retention, \DateTimeImmutable $today): array
    {
        $before = $retention->keptFrom($today)->format('Y-m-d');

        [$absences, $covers, $files] = $this->connection->transactional(function (Connection $db) use ($before, $today): array {
            /** @var list<string> $files */
            $files = $db->fetchFirstColumn('SELECT task_document_path FROM guardia_cover WHERE cover_date < ? AND task_document_path IS NOT NULL', [$before]);
            /** @var list<string> $orderFiles */
            $orderFiles = $db->fetchFirstColumn(
                'SELECT l.path FROM copy_request_line l JOIN copy_request r ON r.id = l.order_id
                 WHERE l.path IS NOT NULL AND r.cover_id IN (SELECT id FROM guardia_cover WHERE cover_date < ?)',
                [$before],
            );

            $this->addGroupMonths($db, $before);
            $db->executeStatement(
                'INSERT IGNORE INTO guardia_bank_group_use (bank_item_id, group_name)
                 SELECT DISTINCT bank_item_id, group_name FROM guardia_cover
                 WHERE cover_date < ? AND bank_item_id IS NOT NULL AND group_name IS NOT NULL AND not_covered = 0',
                [$before],
            );
            $db->executeStatement(
                'INSERT INTO guardia_cover_archive (cover_date, slot_index, assigned_guardia_id, not_covered, grouping_key)
                 SELECT cover_date, slot_index, assigned_guardia_id, not_covered, grouping_id FROM guardia_cover WHERE cover_date < ?',
                [$before],
            );

            // Before the covers go: their deletion clears cover_id, and with it the way to find these. Marked
            // as purged like PastDocumentPurger does, so the listing says there were documents and offers
            // no resend of an order with nothing left to send.
            $db->executeStatement(
                'UPDATE copy_request r SET r.document_purged_at = ?
                 WHERE r.cover_id IN (SELECT id FROM guardia_cover WHERE cover_date < ?)
                   AND EXISTS (SELECT 1 FROM copy_request_line l WHERE l.order_id = r.id AND l.path IS NOT NULL)',
                [$today->format('Y-m-d H:i:s'), $before],
            );
            $db->executeStatement(
                'UPDATE copy_request_line SET path = NULL, name = NULL
                 WHERE order_id IN (SELECT id FROM copy_request WHERE cover_id IN (SELECT id FROM guardia_cover WHERE cover_date < ?))',
                [$before],
            );
            $db->executeStatement(
                'UPDATE copy_request SET context = ?, notes = NULL, requested_by_id = NULL
                 WHERE cover_id IN (SELECT id FROM guardia_cover WHERE cover_date < ?)',
                [self::FORGOTTEN_ORDER_CONTEXT, $before],
            );

            foreach (self::AUDITED as $type => [$table, $dateColumn]) {
                // Its own rows, and the trail of the ones deleted by hand before (no row left to date it
                // by, so the date of the entry): a "deleted" entry carries everything the row held.
                $db->executeStatement(
                    "DELETE FROM audit_log WHERE subject_type = ? AND (
                         subject_id IN (SELECT id FROM {$table} WHERE {$dateColumn} < ?)
                         OR (occurred_at < ? AND subject_id NOT IN (SELECT id FROM {$table}))
                     )",
                    [$type, $before, $before],
                );
            }

            $covers = $db->executeStatement('DELETE FROM guardia_cover WHERE cover_date < ?', [$before]);
            $db->executeStatement('DELETE FROM guardia_grouping WHERE grouping_date < ?', [$before]);
            $absences = $db->executeStatement('DELETE FROM guardia_absence WHERE absence_date < ?', [$before]);
            // By when they were sent, as notices carry no link to their guardia: one sent more than the plazo
            // ahead of a guardia still to come goes too. Rare, as absences are registered days ahead at most.
            $db->executeStatement("DELETE FROM notification WHERE kind LIKE 'guardia.%' AND created_at < ?", [$before]);

            return [(int) $absences, (int) $covers, [...$files, ...$orderFiles]];
        });

        return [
            'absences' => $absences,
            'covers' => $covers,
            'unremoved' => array_values(array_filter($files, fn (string $path): bool => !$this->removed($path))),
        ];
    }

    /**
     * Adds the periods each group is about to lose track of to its month, one row per group: a cover of
     * several groups ("1A, 1B") counts for each.
     *
     * @param Connection $db     the connection, inside the purge's transaction
     * @param string     $before the first day kept, as Y-m-d
     */
    private function addGroupMonths(Connection $db, string $before): void
    {
        /** @var list<array{group_name: string, month: string, periods: int|string}> $rows */
        $rows = $db->fetchAllAssociative(
            "SELECT group_name, DATE_FORMAT(cover_date, '%Y-%m-01') AS month, COUNT(*) AS periods
             FROM guardia_cover WHERE cover_date < ? AND group_name IS NOT NULL GROUP BY group_name, month",
            [$before],
        );

        $periods = [];
        foreach ($rows as $row) {
            foreach (GroupCode::names($row['group_name']) as $group) {
                $periods[$row['month']][$group] = ($periods[$row['month']][$group] ?? 0) + (int) $row['periods'];
            }
        }

        foreach ($periods as $month => $byGroup) {
            foreach ($byGroup as $group => $count) {
                $db->executeStatement(
                    'INSERT INTO guardia_group_month (group_name, month_start, periods) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE periods = periods + VALUES(periods)',
                    [$group, $month, $count],
                );
            }
        }
    }

    /**
     * Deletes one file, telling whether it worked instead of stopping the rest.
     */
    private function removed(string $path): bool
    {
        try {
            $this->uploader->remove($path);

            return true;
        } catch (IOExceptionInterface) {
            return false;
        }
    }
}
