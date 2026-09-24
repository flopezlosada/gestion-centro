<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A weekly meeting group carries the place and the push reminder its generated meetings inherit, so the
 * convener does not have to fill them in on every week's meeting. Both optional: existing groups keep
 * generating exactly as before (no place, no reminder).
 */
final class Version20260924170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Meeting groups get a place and a reminder that their generated meetings inherit.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE meeting_group ADD place VARCHAR(120) DEFAULT NULL, ADD reminder_minutes INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE meeting_group DROP place, DROP reminder_minutes');
    }
}
