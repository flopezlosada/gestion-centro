<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Guardias archivadas al borrar las ausencias pasadas del plazo, horas de guardia por grupo y mes, y tareas del banco hechas por cada grupo';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE guardia_cover_archive (id INT AUTO_INCREMENT NOT NULL, cover_date DATE NOT NULL, slot_index SMALLINT NOT NULL, not_covered TINYINT(1) NOT NULL, grouping_key INT DEFAULT NULL, assigned_guardia_id INT DEFAULT NULL, INDEX IDX_cover_archive_date (cover_date), INDEX IDX_cover_archive_assigned (assigned_guardia_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE guardia_cover_archive ADD CONSTRAINT FK_63B8CD3C284F2787 FOREIGN KEY (assigned_guardia_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('CREATE TABLE guardia_group_month (id INT AUTO_INCREMENT NOT NULL, group_name VARCHAR(64) NOT NULL, month_start DATE NOT NULL, periods INT NOT NULL, UNIQUE INDEX UNIQ_guardia_group_month (group_name, month_start), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE guardia_bank_group_use (id INT AUTO_INCREMENT NOT NULL, group_name VARCHAR(255) NOT NULL, bank_item_id INT NOT NULL, UNIQUE INDEX UNIQ_bank_group_use (bank_item_id, group_name), INDEX IDX_bank_group_use_item (bank_item_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE guardia_bank_group_use ADD CONSTRAINT FK_6AAB53883CFB3AF0 FOREIGN KEY (bank_item_id) REFERENCES guardia_task_bank (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // What was archived does not come back: the absences it came from are gone.
        $this->addSql('DROP TABLE guardia_bank_group_use');
        $this->addSql('DROP TABLE guardia_group_month');
        $this->addSql('DROP TABLE guardia_cover_archive');
    }
}
