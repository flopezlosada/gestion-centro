<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Guardias de recreo: el cuadrante fijo de curso por zonas, y el marco horario del que salen las horas
 * de los recreos.
 *
 * - time_slot: los tramos del día del centro (índice, horas, y si es lectivo o RECREO), importados del
 *   <marcoHorario> del planificador de Peñalara. Hace falta porque schedule_entry solo tiene filas de
 *   los tramos que alguien ocupa, y en los recreos no hay actividad ninguna (comprobado en el export
 *   real del centro: cero actividades en los dos tramos de recreo), así que sin esta tabla las horas de
 *   los recreos no existen en la aplicación.
 * - break_zone: los sitios a vigilar (patio, pasillo, biblioteca, pistas, patio dirigido) con su PESO
 *   —no todas cuestan igual, y el reparto equitativo suma pesos— y cuánta gente necesita cada una.
 *   Sin curso: son sitios, y duran más que un año; la que se deja de usar se archiva, no se borra.
 * - break_duty_assignment: una fila = UNA guardia de recreo (profesor, día, zona y qué recreos cubre).
 *   El único índice por (curso, profesor, día) es lo que garantiza la regla del centro de que cubrir los
 *   dos tramos cuenta como una sola guardia y que nadie esté en dos zonas a la vez.
 * - break_duty_gap: el día en que quien tiene el recreo falta. No se reasigna (no hay personal): se
 *   avisa al equipo directivo y se apunta aquí, con el voluntario si aparece. El único por (guardia,
 *   día) es lo que evita avisar dos veces cuando la ausencia se registra en dos pasos.
 *
 * Nada que backfillar: las cuatro tablas nacen vacías. Las zonas las siembra BreakZoneFixtures
 * (grupo golden); el marco horario se rellena en la siguiente importación de horario del curso.
 */
final class Version20260730120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Guardias de recreo: marco horario (time_slot), zonas, cuadrante fijo y huecos sin vigilar.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE time_slot (id INT AUTO_INCREMENT NOT NULL, academic_year_id INT NOT NULL, slot_index SMALLINT NOT NULL, starts_at TIME NOT NULL, ends_at TIME NOT NULL, kind VARCHAR(16) NOT NULL, UNIQUE INDEX UNIQ_time_slot_year_index (academic_year_id, slot_index), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE time_slot ADD CONSTRAINT FK_time_slot_year FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE break_zone (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(80) NOT NULL, weight SMALLINT DEFAULT 1 NOT NULL, required_teachers SMALLINT DEFAULT 1 NOT NULL, sort_order SMALLINT DEFAULT 0 NOT NULL, archived TINYINT(1) DEFAULT 0 NOT NULL, UNIQUE INDEX UNIQ_break_zone_name (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('CREATE TABLE break_duty_assignment (id INT AUTO_INCREMENT NOT NULL, academic_year_id INT NOT NULL, teacher_id INT NOT NULL, zone_id INT NOT NULL, weekday SMALLINT NOT NULL, periods VARCHAR(8) NOT NULL, UNIQUE INDEX UNIQ_break_duty_teacher_weekday (academic_year_id, teacher_id, weekday), INDEX IDX_break_duty_year_weekday (academic_year_id, weekday), INDEX IDX_break_duty_teacher (teacher_id), INDEX IDX_break_duty_zone (zone_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE break_duty_assignment ADD CONSTRAINT FK_break_duty_year FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE break_duty_assignment ADD CONSTRAINT FK_break_duty_teacher FOREIGN KEY (teacher_id) REFERENCES app_user (id) ON DELETE CASCADE');
        // RESTRICT a propósito: una zona en uso se archiva, nunca se borra, así que ninguna guardia puede
        // quedarse apuntando a un sitio que ya no existe.
        $this->addSql('ALTER TABLE break_duty_assignment ADD CONSTRAINT FK_break_duty_zone FOREIGN KEY (zone_id) REFERENCES break_zone (id) ON DELETE RESTRICT');

        $this->addSql('CREATE TABLE break_duty_gap (id INT AUTO_INCREMENT NOT NULL, assignment_id INT NOT NULL, volunteer_id INT DEFAULT NULL, gap_date DATE NOT NULL, note LONGTEXT DEFAULT NULL, UNIQUE INDEX UNIQ_break_gap_duty_date (assignment_id, gap_date), INDEX IDX_break_gap_date (gap_date), INDEX IDX_break_gap_volunteer (volunteer_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE break_duty_gap ADD CONSTRAINT FK_break_gap_assignment FOREIGN KEY (assignment_id) REFERENCES break_duty_assignment (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE break_duty_gap ADD CONSTRAINT FK_break_gap_volunteer FOREIGN KEY (volunteer_id) REFERENCES app_user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // En orden inverso a las dependencias: los huecos cuelgan del cuadrante, y el cuadrante de las
        // zonas (con RESTRICT, así que la tabla de zonas no puede caer antes).
        $this->addSql('DROP TABLE break_duty_gap');
        $this->addSql('DROP TABLE break_duty_assignment');
        $this->addSql('DROP TABLE break_zone');
        $this->addSql('DROP TABLE time_slot');
    }
}
