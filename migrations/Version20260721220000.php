<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Módulo de guardias: horario importado de Peñalara y parte diario de guardias.
 *
 * - app_user.penalara_code: clave estable del profesor en Peñalara (X_EMPLEADO) para re-enlazar el
 *   horario en cada importación sin volver a emparejar por nombre.
 * - schedule_entry: una celda del horario semanal (lectiva, guardia o colaboración) por profesor.
 * - guardia_cover: una línea del parte (ausencia de un profesor un día a una hora, con el grupo
 *   descubierto, la guardia asignada y la confirmación).
 */
final class Version20260721220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Módulo de guardias: penalara_code, schedule_entry y guardia_cover.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD penalara_code VARCHAR(32) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_user_penalara ON app_user (penalara_code)');

        $this->addSql('CREATE TABLE schedule_entry (
            id INT AUTO_INCREMENT NOT NULL,
            teacher_id INT NOT NULL,
            weekday SMALLINT NOT NULL,
            slot_index SMALLINT NOT NULL,
            starts_at TIME NOT NULL,
            ends_at TIME NOT NULL,
            kind VARCHAR(16) NOT NULL,
            group_name VARCHAR(64) DEFAULT NULL,
            room_name VARCHAR(64) DEFAULT NULL,
            subject_name VARCHAR(128) DEFAULT NULL,
            INDEX IDX_sched_slot_kind (weekday, slot_index, kind),
            INDEX IDX_sched_teacher (teacher_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE schedule_entry ADD CONSTRAINT FK_sched_teacher FOREIGN KEY (teacher_id) REFERENCES app_user (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE guardia_cover (
            id INT AUTO_INCREMENT NOT NULL,
            absent_teacher_id INT NOT NULL,
            assigned_guardia_id INT DEFAULT NULL,
            cover_date DATE NOT NULL,
            slot_index SMALLINT NOT NULL,
            group_name VARCHAR(64) DEFAULT NULL,
            room_name VARCHAR(64) DEFAULT NULL,
            task_note LONGTEXT DEFAULT NULL,
            confirmed TINYINT(1) DEFAULT 0 NOT NULL,
            INDEX IDX_cover_date_slot (cover_date, slot_index),
            INDEX IDX_cover_assigned (assigned_guardia_id),
            UNIQUE INDEX UNIQ_cover_absence (absent_teacher_id, cover_date, slot_index),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE guardia_cover ADD CONSTRAINT FK_cover_absent FOREIGN KEY (absent_teacher_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE guardia_cover ADD CONSTRAINT FK_cover_assigned FOREIGN KEY (assigned_guardia_id) REFERENCES app_user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE guardia_cover DROP FOREIGN KEY FK_cover_absent');
        $this->addSql('ALTER TABLE guardia_cover DROP FOREIGN KEY FK_cover_assigned');
        $this->addSql('DROP TABLE guardia_cover');
        $this->addSql('ALTER TABLE schedule_entry DROP FOREIGN KEY FK_sched_teacher');
        $this->addSql('DROP TABLE schedule_entry');
        $this->addSql('DROP INDEX UNIQ_user_penalara ON app_user');
        $this->addSql('ALTER TABLE app_user DROP penalara_code');
    }
}
