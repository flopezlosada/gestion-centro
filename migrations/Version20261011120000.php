<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Whoever convenes a meeting becomes a convened person like any other, instead of being counted in by
 * force. Until now the convener was never in `meeting_attendee` and the app added them to the people of
 * every meeting (reminder, roll, acta); from now on only the list counts, and the convener is on it by
 * default but may leave it when they convene without attending.
 *
 * So every existing meeting gets its convener added to the list: that is exactly what the app assumed of
 * them, and nothing already convened changes. INSERT IGNORE only guards a re-run: the app never let a
 * convener onto their own list, so no row should clash.
 *
 * Irreversible on purpose: `down()` cannot tell the rows added here from a convener who ticked themselves
 * afterwards, and removing both would drop people from meetings they are convened to.
 */
final class Version20261011120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add each meeting convener to its convened list (meeting_attendee).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('INSERT IGNORE INTO meeting_attendee (meeting_id, user_id) SELECT id, convener_id FROM meeting WHERE convener_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
    }
}
