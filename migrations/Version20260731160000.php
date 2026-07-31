<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Registro de incidencias TIC.
 *
 * El centro las lleva hoy en el Aula Virtual y pidió tenerlas aquí, con la prioridad (alta/media/baja),
 * el aula, el grupo que estaba dentro, la hora y quién avisa; y, si el equipo es de uso individual, sin
 * grupo y marcado como tal.
 *
 * `priority_weight` es la prioridad MATERIALIZADA como número solo para poder ordenar en SQL: "high"
 * va detrás de "low" por orden alfabético, que es justo al revés de lo que hace falta.
 */
final class Version20260731160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Incidencias TIC: registro con prioridad, aula, grupo y hora.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tic_incident (
            id INT AUTO_INCREMENT NOT NULL,
            reported_by_id INT DEFAULT NULL,
            room_id INT DEFAULT NULL,
            taken_by_id INT DEFAULT NULL,
            resolved_by_id INT DEFAULT NULL,
            equipment VARCHAR(120) NOT NULL,
            description LONGTEXT NOT NULL,
            individual_use TINYINT(1) DEFAULT 0 NOT NULL,
            group_name VARCHAR(40) DEFAULT NULL,
            occurred_at DATETIME NOT NULL,
            priority VARCHAR(10) NOT NULL,
            priority_weight SMALLINT DEFAULT 1 NOT NULL,
            status VARCHAR(20) DEFAULT \'open\' NOT NULL,
            resolution_note LONGTEXT DEFAULT NULL,
            resolved_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_tic_incident_open (status, priority_weight, occurred_at),
            INDEX IDX_tic_incident_room (room_id),
            INDEX IDX_tic_incident_reporter (reported_by_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4');

        // Todas las personas van con SET NULL: que alguien se vaya del centro no puede borrar el
        // historial de averías. El aula igual — un aula retirada del catálogo no borra su historia.
        $this->addSql('ALTER TABLE tic_incident ADD CONSTRAINT FK_tic_incident_reporter FOREIGN KEY (reported_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE tic_incident ADD CONSTRAINT FK_tic_incident_taken FOREIGN KEY (taken_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE tic_incident ADD CONSTRAINT FK_tic_incident_resolver FOREIGN KEY (resolved_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE tic_incident ADD CONSTRAINT FK_tic_incident_room FOREIGN KEY (room_id) REFERENCES room (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tic_incident');
    }
}
