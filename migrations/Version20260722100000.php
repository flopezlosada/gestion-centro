<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ata el horario importado al curso escolar: schedule_entry.academic_year_id → academic_year.
 *
 * Los horarios cambian cada curso, así que una celda de horario pertenece a un AcademicYear concreto;
 * la importación reemplaza solo las entradas de su curso y el parte lee el horario del curso al que
 * pertenece la fecha consultada. schedule_entry es dato de referencia reimportable, de modo que las
 * filas previas (sin curso) se descartan antes de añadir la columna NOT NULL.
 */
final class Version20260722100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ata schedule_entry al curso escolar (academic_year_id).';
    }

    public function up(Schema $schema): void
    {
        // Reference data, reimportable: clear it so the NOT NULL FK can be added on a clean table.
        $this->addSql('DELETE FROM schedule_entry');

        // IDX_sched_teacher (teacher_id) stays: it backs the teacher foreign key. Only the slot index
        // is swapped for one led by academic_year_id, which also backs the new course foreign key.
        $this->addSql('DROP INDEX IDX_sched_slot_kind ON schedule_entry');

        $this->addSql('ALTER TABLE schedule_entry ADD academic_year_id INT NOT NULL');
        // Created before the FK and led by academic_year_id, so it satisfies the FK's index requirement
        // (leftmost prefix) and no redundant single-column index is auto-created.
        $this->addSql('CREATE INDEX IDX_sched_year_slot_kind ON schedule_entry (academic_year_id, weekday, slot_index, kind)');
        $this->addSql('ALTER TABLE schedule_entry ADD CONSTRAINT FK_sched_year FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule_entry DROP FOREIGN KEY FK_sched_year');
        $this->addSql('DROP INDEX IDX_sched_year_slot_kind ON schedule_entry');
        $this->addSql('ALTER TABLE schedule_entry DROP academic_year_id');
        $this->addSql('CREATE INDEX IDX_sched_slot_kind ON schedule_entry (weekday, slot_index, kind)');
    }
}
