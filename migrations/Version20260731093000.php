<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tope de sesiones por persona en un plan (jornadas culturales).
 *
 * El centro pidió "especificar cuántas sesiones cubre cada profe" ("dos sesiones y dos guardias, o
 * cuatro guardias y una sesión"). Es UN número para todo el plan, no un cupo por persona: una pantalla
 * con una casilla para cada uno de los ~80 docentes no la rellena nadie, y el reparto ya iguala la carga
 * por sí solo. Las excepciones reales se resuelven cambiando la línea concreta.
 *
 * NULL = sin tope, que es lo razonable para un plan que no sea una jornada de talleres.
 */
final class Version20260731093000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'space_plan: máximo de sesiones por persona.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE space_plan ADD staff_quota SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE space_plan DROP staff_quota');
    }
}
