<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Catálogo de espacios del centro (fase 1 de gestión de espacios).
 *
 * - `room`: la ficha de cada aula/laboratorio/pista. El código es el que usa el horario de Peñalara
 *   ("2IN5", "S ACTOS"), normalizado en mayúsculas, y es único. Capacidad y tipo NO vienen del export
 *   (verificado sobre el planificador real: <aula> solo trae nombre y clave), así que nacen a null /
 *   'other' y los completa una persona.
 * - `schedule_entry.room_id`: enlace de cada celda de horario con su ficha, para que el cálculo de
 *   ocupación no dependa de comparar cadenas. Nullable: las guardias no ocupan aula, y las celdas ya
 *   importadas quedan sin enlazar hasta que corra `app:sync-rooms`.
 *
 * No hay backfill aquí a propósito: crear las fichas exige leer los nombres de aula y normalizarlos,
 * que es justo lo que hace `RoomSynchroniser`. Tras migrar, en staging y en producción hay que
 * ejecutar `app:sync-rooms` (o pulsar "Sincronizar con el horario" en /espacios/catalogo); mientras no
 * se haga, la pantalla de aulas libres avisa de cuántas celdas quedan sin espacio catalogado.
 */
final class Version20260731090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Catálogo de espacios (room) y enlace de las celdas de horario con su espacio.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE room (
            id INT AUTO_INCREMENT NOT NULL,
            code VARCHAR(64) NOT NULL,
            name VARCHAR(128) NOT NULL,
            kind VARCHAR(24) DEFAULT 'other' NOT NULL,
            capacity SMALLINT DEFAULT NULL,
            building VARCHAR(32) DEFAULT NULL,
            floor_level SMALLINT DEFAULT NULL,
            assignable TINYINT(1) DEFAULT 1 NOT NULL,
            active TINYINT(1) DEFAULT 1 NOT NULL,
            notes LONGTEXT DEFAULT NULL,
            UNIQUE INDEX uniq_room_code (code),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql('ALTER TABLE schedule_entry ADD room_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE schedule_entry ADD CONSTRAINT FK_schedule_entry_room FOREIGN KEY (room_id) REFERENCES room (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_sched_room ON schedule_entry (room_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule_entry DROP FOREIGN KEY FK_schedule_entry_room');
        $this->addSql('DROP INDEX IDX_sched_room ON schedule_entry');
        $this->addSql('ALTER TABLE schedule_entry DROP room_id');
        $this->addSql('DROP TABLE room');
    }
}
