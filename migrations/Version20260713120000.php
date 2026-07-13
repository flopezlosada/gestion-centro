<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Enforces unique event category names at the database level, so the admin catalogue cannot hold two
 * categories with the same name (which would be indistinguishable in the event form's picker).
 */
final class Version20260713120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Nombre de categoría de evento único (uniq_event_category_name).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_event_category_name ON event_category (name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_event_category_name ON event_category');
    }
}
