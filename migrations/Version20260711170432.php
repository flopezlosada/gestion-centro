<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the non_lective_day table: the school's non-teaching days (holidays, closures) that,
 * together with weekends, block task deadlines. One row per date (the date is unique).
 */
final class Version20260711170432 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea la tabla non_lective_day (días no lectivos del calendario escolar).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE non_lective_day (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, description VARCHAR(120) NOT NULL, UNIQUE INDEX UNIQ_23D16F5CAA9E377A (date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE non_lective_day');
    }
}
