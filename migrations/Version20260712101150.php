<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260712101150 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Estructura del curso: fechas de inicio/fin de los tres trimestres (academic_year).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE academic_year (id INT AUTO_INCREMENT NOT NULL, school_year VARCHAR(9) NOT NULL, term1_start DATE NOT NULL, term1_end DATE NOT NULL, term2_start DATE NOT NULL, term2_end DATE NOT NULL, term3_start DATE NOT NULL, term3_end DATE NOT NULL, UNIQUE INDEX UNIQ_275AE721FAAAACDA (school_year), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE academic_year');
    }
}
