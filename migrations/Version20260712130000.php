<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recurring personal events: a series_id shared by the occurrences of one recurring entry.
 */
final class Version20260712130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Eventos personales recurrentes: personal_event.series_id (agrupa las ocurrencias de una serie).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE personal_event ADD series_id VARCHAR(32) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_personal_event_owner_series ON personal_event (owner_id, series_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_personal_event_owner_series ON personal_event');
        $this->addSql('ALTER TABLE personal_event DROP series_id');
    }
}
