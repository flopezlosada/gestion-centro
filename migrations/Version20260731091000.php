<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Planes de espacios (fase 2 de gestión de espacios): el expediente completo de un cambio de aula.
 *
 * Cuatro tablas, una por concepto distinto:
 *  - `space_plan`: el expediente (qué, cuándo, a quién le desaparece el horario, en qué estado está).
 *  - `space_plan_activity`: el ENUNCIADO, lo que el evento mete en el centro. No depende de la
 *    alternativa que se elija, por eso cuelga del plan.
 *  - `space_plan_option`: una alternativa completa, con sus cifras para poder compararla.
 *  - `space_plan_assignment`: una línea ("el martes a 3.ª, E1B se va de 2IN5 a 0LC7"). Cuelga de la
 *    OPCIÓN, no del plan: dos alternativas dicen cosas distintas del mismo momento.
 *
 * `chosen_option_id` se añade DESPUÉS de crear space_plan_option porque se referencian mutuamente.
 *
 * Nada de esto altera el horario por sí solo: solo la opción elegida de un plan en estado 'approved'
 * entra en la rejilla efectiva.
 */
final class Version20260731091000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Planes de espacios: expediente, enunciado, alternativas y líneas.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE space_plan (
            id INT AUTO_INCREMENT NOT NULL,
            academic_year_id INT NOT NULL,
            created_by_id INT NOT NULL,
            approved_by_id INT DEFAULT NULL,
            chosen_option_id INT DEFAULT NULL,
            kind VARCHAR(24) NOT NULL,
            title VARCHAR(160) NOT NULL,
            public_reason VARCHAR(255) DEFAULT NULL,
            internal_notes LONGTEXT DEFAULT NULL,
            date_from DATE NOT NULL,
            date_to DATE NOT NULL,
            slot_from SMALLINT DEFAULT NULL,
            slot_to SMALLINT DEFAULT NULL,
            substitution_scope VARCHAR(16) DEFAULT 'none' NOT NULL,
            scope_group_names JSON NOT NULL,
            status VARCHAR(16) DEFAULT 'draft' NOT NULL,
            approved_at DATETIME DEFAULT NULL,
            notified_at DATETIME DEFAULT NULL,
            INDEX IDX_space_plan_dates (academic_year_id, date_from, date_to),
            INDEX IDX_space_plan_status (status),
            INDEX IDX_space_plan_created_by (created_by_id),
            INDEX IDX_space_plan_approved_by (approved_by_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql("CREATE TABLE space_plan_activity (
            id INT AUTO_INCREMENT NOT NULL,
            plan_id INT NOT NULL,
            room_id INT DEFAULT NULL,
            title VARCHAR(160) NOT NULL,
            fixed_date DATE DEFAULT NULL,
            fixed_slots JSON NOT NULL,
            sessions SMALLINT DEFAULT NULL,
            required_capacity SMALLINT DEFAULT NULL,
            required_kind VARCHAR(24) DEFAULT NULL,
            target_group_names JSON NOT NULL,
            INDEX IDX_space_activity_plan (plan_id),
            INDEX IDX_space_activity_room (room_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql("CREATE TABLE space_plan_option (
            id INT AUTO_INCREMENT NOT NULL,
            plan_id INT NOT NULL,
            label VARCHAR(32) NOT NULL,
            strategy VARCHAR(32) NOT NULL,
            rationale VARCHAR(255) NOT NULL,
            metrics JSON NOT NULL,
            generated_at DATETIME NOT NULL,
            INDEX IDX_space_option_plan (plan_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql("CREATE TABLE space_plan_assignment (
            id INT AUTO_INCREMENT NOT NULL,
            option_id INT NOT NULL,
            room_id INT DEFAULT NULL,
            teacher_id INT DEFAULT NULL,
            source_entry_id INT DEFAULT NULL,
            date DATE NOT NULL,
            slot_index SMALLINT NOT NULL,
            kind VARCHAR(16) NOT NULL,
            origin_room_name VARCHAR(64) DEFAULT NULL,
            group_names VARCHAR(255) DEFAULT NULL,
            subject_name VARCHAR(128) DEFAULT NULL,
            activity_title VARCHAR(160) DEFAULT NULL,
            manually_edited TINYINT(1) DEFAULT 0 NOT NULL,
            note VARCHAR(255) DEFAULT NULL,
            INDEX IDX_space_assignment_option (option_id, date, slot_index),
            INDEX IDX_space_assignment_when (date, slot_index),
            INDEX IDX_space_assignment_room (room_id),
            INDEX IDX_space_assignment_teacher (teacher_id),
            INDEX IDX_space_assignment_source (source_entry_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");

        $this->addSql('ALTER TABLE space_plan ADD CONSTRAINT FK_space_plan_year FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE space_plan ADD CONSTRAINT FK_space_plan_created_by FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE space_plan ADD CONSTRAINT FK_space_plan_approved_by FOREIGN KEY (approved_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE space_plan_activity ADD CONSTRAINT FK_space_activity_plan FOREIGN KEY (plan_id) REFERENCES space_plan (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE space_plan_activity ADD CONSTRAINT FK_space_activity_room FOREIGN KEY (room_id) REFERENCES room (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE space_plan_option ADD CONSTRAINT FK_space_option_plan FOREIGN KEY (plan_id) REFERENCES space_plan (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE space_plan_assignment ADD CONSTRAINT FK_space_assignment_option FOREIGN KEY (option_id) REFERENCES space_plan_option (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE space_plan_assignment ADD CONSTRAINT FK_space_assignment_room FOREIGN KEY (room_id) REFERENCES room (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE space_plan_assignment ADD CONSTRAINT FK_space_assignment_teacher FOREIGN KEY (teacher_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE space_plan_assignment ADD CONSTRAINT FK_space_assignment_source FOREIGN KEY (source_entry_id) REFERENCES schedule_entry (id) ON DELETE SET NULL');

        // El plan apunta a la opción elegida y la opción al plan: la FK cruzada va después de que
        // existan las dos tablas.
        $this->addSql('ALTER TABLE space_plan ADD CONSTRAINT FK_space_plan_chosen FOREIGN KEY (chosen_option_id) REFERENCES space_plan_option (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE space_plan DROP FOREIGN KEY FK_space_plan_chosen');
        $this->addSql('DROP TABLE space_plan_assignment');
        $this->addSql('DROP TABLE space_plan_option');
        $this->addSql('DROP TABLE space_plan_activity');
        $this->addSql('DROP TABLE space_plan');
    }
}
