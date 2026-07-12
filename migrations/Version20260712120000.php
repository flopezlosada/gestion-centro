<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Personal agenda entries: private events owned by a single user (personal_event).
 */
final class Version20260712120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agenda personal del profesor: tabla personal_event (eventos privados por usuario).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE personal_event (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(200) NOT NULL, description LONGTEXT DEFAULT NULL, start_at DATETIME NOT NULL, end_at DATETIME DEFAULT NULL, all_day TINYINT NOT NULL, done TINYINT NOT NULL, created_at DATETIME NOT NULL, owner_id INT NOT NULL, INDEX IDX_63F74CB17E3C61F9 (owner_id), INDEX idx_personal_event_owner_start (owner_id, start_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE personal_event ADD CONSTRAINT FK_63F74CB17E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE personal_event DROP FOREIGN KEY FK_63F74CB17E3C61F9');
        $this->addSql('DROP TABLE personal_event');
    }
}
