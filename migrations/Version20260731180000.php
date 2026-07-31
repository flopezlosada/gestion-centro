<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Reserva de espacios y material.
 *
 *  - `material` es el catálogo de lo que se comparte (radio, cámara, carros de portátiles, móvil de
 *    extraescolares). Los espacios NO se siembran aquí: ya salen del horario importado, en `room`.
 *  - `booking` es una reserva: quién, qué (un aula o un material, exactamente uno de los dos), qué día y
 *    en qué hora del horario.
 *
 * La clave del asunto es el índice ÚNICO sobre (`resource_key`, `booked_on`, `slot_index`).
 * `resource_key` es "room:12" o "material:3", derivado de la columna que esté puesta, y existe justamente
 * para poder tener ese índice: con dos columnas anulables no se puede, y sin él dos personas reservando
 * el mismo carro a la vez ganarían las dos. Aquí la segunda rebota contra la base de datos.
 */
final class Version20260731180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reservas: catálogo de material y reservas de espacio/material por día y hora.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE material (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(120) NOT NULL,
            kept_at VARCHAR(120) DEFAULT NULL,
            notes LONGTEXT DEFAULT NULL,
            active TINYINT(1) DEFAULT 1 NOT NULL,
            UNIQUE INDEX UNIQ_material_name (name),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('CREATE TABLE booking (
            id INT AUTO_INCREMENT NOT NULL,
            booked_by_id INT DEFAULT NULL,
            room_id INT DEFAULT NULL,
            material_id INT DEFAULT NULL,
            resource_key VARCHAR(32) NOT NULL,
            booked_on DATE NOT NULL,
            slot_index SMALLINT NOT NULL,
            purpose VARCHAR(200) NOT NULL,
            group_name VARCHAR(40) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE INDEX uniq_booking_slot (resource_key, booked_on, slot_index),
            INDEX idx_booking_day (booked_on, slot_index),
            INDEX idx_booking_person (booked_by_id, booked_on),
            INDEX IDX_booking_room (room_id),
            INDEX IDX_booking_material (material_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4');

        // La persona con SET NULL (que alguien se vaya no borra la reserva del martes); el recurso con
        // CASCADE, porque una reserva de algo que ya no existe no reserva nada.
        $this->addSql('ALTER TABLE booking ADD CONSTRAINT FK_booking_person FOREIGN KEY (booked_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE booking ADD CONSTRAINT FK_booking_room FOREIGN KEY (room_id) REFERENCES room (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE booking ADD CONSTRAINT FK_booking_material FOREIGN KEY (material_id) REFERENCES material (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE booking');
        $this->addSql('DROP TABLE material');
    }
}
