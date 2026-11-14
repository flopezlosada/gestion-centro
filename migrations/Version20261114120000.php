<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Si una ausencia la apuntó entera el propio docente: solo entonces puede anularla él mismo. Las que ya
 * existen quedan en «no»: no se sabe quién las apuntó y anularlas sigue siendo cosa de coordinación.
 */
final class Version20261114120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ausencia apuntada por el propio docente (guardia_absence.self_reported).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE guardia_absence ADD self_reported TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE guardia_absence DROP self_reported');
    }
}
