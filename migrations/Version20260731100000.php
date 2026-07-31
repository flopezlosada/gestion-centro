<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tamaño observado del aula: cuántos grupos mete el horario a la vez en ella.
 *
 * Unifica las dos filosofías de «aulas libres» que llegaron a main por caminos distintos: la del módulo
 * de espacios (el centro clasifica el aula a mano, `room_size`) y la de las guardias (el tamaño se deduce
 * de la evidencia del horario). Las dos son buenas por separado y juntas son mejores: la evidencia
 * PROPONE un tamaño para las 40 fichas que nadie ha rellenado todavía, y lo que teclea el centro CORRIGE.
 *
 * Columna del sistema, no del centro: la reescribe `RoomSynchroniser` en cada importación. Se rellena en
 * el primer `app:sync-rooms` (o en la primera importación) posterior a esta migración; hasta entonces
 * null, que es lo que había antes de haberla.
 */
final class Version20260731100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'room: cuántos grupos ha visto el horario a la vez en cada espacio.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE room ADD observed_groups SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE room DROP observed_groups');
    }
}
