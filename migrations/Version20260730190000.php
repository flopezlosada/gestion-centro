<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tamaño del aula medido en GRUPOS, que es como lo mide el centro.
 *
 * Respuesta literal del equipo directivo (2026-07-30): "aulas normales, aulas específicas de pequeño
 * tamaño (para 15 alumnos), aulas grandes de gran tamaño (para dos grupos, para más de tres grupos)".
 * Nadie sabe cuántos alumnos tiene cada grupo —Peñalara no trae matrícula— pero todo el mundo sabe si
 * en un aula caben dos grupos. `capacity` (plazas) se queda como dato opcional para donde el límite sea
 * de aforo real; el criterio de reubicación es este.
 *
 * Nace a NULL en todas las fichas: es justo lo que el centro tiene que rellenar.
 */
final class Version20260730190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'room: tamaño en grupos (pequeña / un grupo / dos grupos / tres o más).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE room ADD room_size VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE room DROP room_size');
    }
}
