<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Marks which org units are departments (Matemáticas, Lengua…) vs leadership boxes (dirección,
 * jefatura de estudios). Only departments are offered when a task's responsibility is a
 * per-department role. Defaults to 1 — most units are departments; leadership boxes are set to 0.
 */
final class Version20260713160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'org_unit.is_department (departamento vs caja de liderazgo).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE org_unit ADD is_department TINYINT(1) DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE org_unit DROP is_department');
    }
}
