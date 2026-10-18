<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Actividades del centro abiertas a familias (sin datos de familias todavía)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE family_activity (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(200) NOT NULL, description LONGTEXT DEFAULT NULL, place VARCHAR(120) DEFAULT NULL, start_at DATETIME NOT NULL, end_at DATETIME DEFAULT NULL, families_wanted SMALLINT DEFAULT NULL, created_at DATETIME NOT NULL, organizer_id INT DEFAULT NULL, INDEX IDX_6ECEE229876C4DDA (organizer_id), INDEX idx_family_activity_start (start_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE family_activity ADD CONSTRAINT FK_6ECEE229876C4DDA FOREIGN KEY (organizer_id) REFERENCES app_user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE family_activity DROP FOREIGN KEY FK_6ECEE229876C4DDA');
        $this->addSql('DROP TABLE family_activity');
    }
}
