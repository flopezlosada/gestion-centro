<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The application no longer records WHY a teacher is away: it organises who covers each class, and a
 * free-text reason ended up holding health or employment details that must not live here.
 *
 * Dropping the column is not enough. Every edit of an absence was audited, so the reasons typed so far
 * also sit in `audit_log.changes` (as `{"reason": {"old": …, "new": …}}`, both on creation and on each
 * edit). They are stripped from there too, and an "updated" entry whose ONLY change was the reason is
 * removed, since it would be left describing nothing.
 *
 * Irreversible on purpose: `down()` brings the column back empty. Restoring the reasons is exactly what
 * this migration exists to make impossible.
 */
final class Version20261005214317 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop guardia_absence.reason and purge the absence reasons kept in audit_log.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE audit_log SET changes = JSON_REMOVE(changes, '$.reason') WHERE subject_type = 'Absence' AND changes IS NOT NULL AND JSON_CONTAINS_PATH(changes, 'one', '$.reason')");
        $this->addSql("DELETE FROM audit_log WHERE subject_type = 'Absence' AND action LIKE '%.updated' AND changes IS NOT NULL AND JSON_LENGTH(changes) = 0");
        $this->addSql('ALTER TABLE guardia_absence DROP reason');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE guardia_absence ADD reason LONGTEXT DEFAULT NULL');
    }
}
