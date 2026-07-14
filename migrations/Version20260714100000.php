<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds role.hierarchy_level: the rank of a role in the school's chain of command (higher = more
 * senior), null for roles that carry no hierarchy. Backfills the known ranked roles: dirección (40),
 * jefatura de estudios (30), jefatura de estudios adjunta (20), jefatura de departamento (10). Every
 * other role (docente, tutor, TIC, secretaría…) stays null. This becomes the single source of truth
 * for superiority, replacing org_unit.manager (removed in a later phase).
 */
final class Version20260714100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'role.hierarchy_level (rango en la cadena de mando) + backfill de los roles con rango.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE role ADD hierarchy_level INT DEFAULT NULL');
        $this->addSql("UPDATE role SET hierarchy_level = 40 WHERE code = 'direction'");
        $this->addSql("UPDATE role SET hierarchy_level = 30 WHERE code = 'head_of_studies'");
        $this->addSql("UPDATE role SET hierarchy_level = 20 WHERE code = 'head_of_studies_deputy'");
        $this->addSql("UPDATE role SET hierarchy_level = 10 WHERE code = 'head_dept'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE role DROP hierarchy_level');
    }
}
