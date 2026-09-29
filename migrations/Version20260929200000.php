<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The agenda, discussion and agreements of a meeting go from plain text to formatted text (HTML written by
 * the editor). What was already written was plain text, read with its line breaks: turned here into the
 * same HTML the editor would produce — escaped, one line per break inside a div — so it reads exactly as
 * before and opens in the editor as it was typed. Rendering it as HTML without this would glue the lines
 * together, and anything that looked like a tag would stop being text.
 *
 * Not reversible: after this, rows written with the editor carry real formatting that plain text cannot
 * hold, so going back would have to drop it.
 */
final class Version20260929200000 extends AbstractMigration
{
    private const array COLUMNS = ['agenda', 'discussion', 'agreements'];

    public function getDescription(): string
    {
        return 'Meeting agenda/discussion/agreements: existing plain text converted to the editor\'s HTML.';
    }

    public function up(Schema $schema): void
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, agenda, discussion, agreements FROM meeting WHERE agenda IS NOT NULL OR discussion IS NOT NULL OR agreements IS NOT NULL');

        foreach ($rows as $row) {
            $set = [];
            $params = ['id' => $row['id']];
            foreach (self::COLUMNS as $column) {
                if (null !== $row[$column]) {
                    $set[] = $column.' = :'.$column;
                    $params[$column] = self::toHtml((string) $row[$column]);
                }
            }
            $this->addSql('UPDATE meeting SET '.implode(', ', $set).' WHERE id = :id', $params);
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Formatted meeting text cannot be turned back into plain text without losing its formatting.');
    }

    /**
     * Plain text as the editor would have written it: escaped, its line breaks kept as <br>.
     */
    private static function toHtml(string $text): string
    {
        $lines = preg_split('/\R/u', trim($text)) ?: [];

        return '<div>'.implode('<br>', array_map(static fn (string $line): string => htmlspecialchars($line, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'), $lines)).'</div>';
    }
}
