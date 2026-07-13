<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Colour-coding category for personal events (personal_event.category), defaulting to "general".
 */
final class Version20260713100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Categoría de color para eventos personales: personal_event.category (default "general").';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE personal_event ADD category VARCHAR(20) DEFAULT 'general' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE personal_event DROP category');
    }
}
