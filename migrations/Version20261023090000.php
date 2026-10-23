<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recreos sin vigilar archivados al borrar las ausencias pasadas del plazo';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE break_duty_gap_archive (id INT AUTO_INCREMENT NOT NULL, gap_date DATE NOT NULL, academic_year_id INT NOT NULL, volunteer_id INT DEFAULT NULL, INDEX IDX_break_gap_archive_year (academic_year_id), INDEX IDX_break_gap_archive_volunteer (volunteer_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE break_duty_gap_archive ADD CONSTRAINT FK_DD19A18C54F3401 FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE break_duty_gap_archive ADD CONSTRAINT FK_DD19A188EFAB6B1 FOREIGN KEY (volunteer_id) REFERENCES app_user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // What was archived does not come back: the absences it came from are gone.
        $this->addSql('DROP TABLE break_duty_gap_archive');
    }
}
