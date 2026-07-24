<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Guardias — la tarea deja de ser una nota de texto y pasa a ser un documento adjunto por clase más
 * una descripción; y el motivo de la ausencia (privado) se guarda una sola vez por ausencia.
 *
 * Nueva tabla guardia_absence (una fila por profesor ausente y día, con el motivo). guardia_cover
 * cuelga de ella (absence_id NOT NULL, ON DELETE CASCADE) y estrena task_document_path/name +
 * task_description en lugar de task_note. Backfill sin pérdida: se crea una ausencia por cada
 * (profesor, día) ya existente, se enlazan sus covers y la vieja task_note se migra a task_description
 * (el motivo queda en blanco: no existía). Idempotente respecto al dato reimportable.
 */
final class Version20260724120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Guardias: tarea = documento + descripción por clase; motivo de ausencia privado en guardia_absence.';
    }

    public function up(Schema $schema): void
    {
        // Ausencia: una por (profesor, día); guarda el motivo privado.
        $this->addSql('CREATE TABLE guardia_absence (id INT AUTO_INCREMENT NOT NULL, absent_teacher_id INT NOT NULL, absence_date DATE NOT NULL, reason LONGTEXT DEFAULT NULL, UNIQUE INDEX UNIQ_guardia_absence (absent_teacher_id, absence_date), INDEX IDX_guardia_absence_teacher (absent_teacher_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE guardia_absence ADD CONSTRAINT FK_guardia_absence_teacher FOREIGN KEY (absent_teacher_id) REFERENCES app_user (id) ON DELETE CASCADE');

        // Nuevas columnas de la línea del parte (absence_id nullable de momento, para poder backfillar).
        // group_name se amplía a 255: una actividad multigrupo guarda la lista de grupos, no cabe en 64.
        $this->addSql('ALTER TABLE guardia_cover ADD absence_id INT DEFAULT NULL, ADD task_document_path VARCHAR(255) DEFAULT NULL, ADD task_document_name VARCHAR(255) DEFAULT NULL, ADD task_description LONGTEXT DEFAULT NULL, CHANGE group_name group_name VARCHAR(255) DEFAULT NULL');

        // Backfill: una ausencia por cada (profesor, día) existente; enlazar covers; nota vieja → descripción.
        $this->addSql('INSERT INTO guardia_absence (absent_teacher_id, absence_date, reason) SELECT DISTINCT absent_teacher_id, cover_date, NULL FROM guardia_cover');
        $this->addSql('UPDATE guardia_cover c INNER JOIN guardia_absence a ON a.absent_teacher_id = c.absent_teacher_id AND a.absence_date = c.cover_date SET c.absence_id = a.id');
        $this->addSql('UPDATE guardia_cover SET task_description = task_note WHERE task_note IS NOT NULL');

        // Con todos los covers enlazados, fijar la FK obligatoria y soltar la vieja columna de texto.
        $this->addSql('ALTER TABLE guardia_cover MODIFY absence_id INT NOT NULL');
        $this->addSql('ALTER TABLE guardia_cover ADD CONSTRAINT FK_guardia_cover_absence FOREIGN KEY (absence_id) REFERENCES guardia_absence (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_guardia_cover_absence ON guardia_cover (absence_id)');
        $this->addSql('ALTER TABLE guardia_cover DROP task_note');
    }

    public function down(Schema $schema): void
    {
        // Recuperar la nota de texto desde la descripción antes de deshacer.
        $this->addSql('ALTER TABLE guardia_cover ADD task_note LONGTEXT DEFAULT NULL');
        $this->addSql('UPDATE guardia_cover SET task_note = task_description WHERE task_description IS NOT NULL');

        $this->addSql('ALTER TABLE guardia_cover DROP FOREIGN KEY FK_guardia_cover_absence');
        $this->addSql('DROP INDEX IDX_guardia_cover_absence ON guardia_cover');
        // Multi-group covers may hold a group list longer than 64; trim before narrowing so a strict
        // MariaDB (staging is 10.11, strict by default) does not abort the down migration on "Data too long".
        $this->addSql('UPDATE guardia_cover SET group_name = LEFT(group_name, 64) WHERE CHAR_LENGTH(group_name) > 64');
        $this->addSql('ALTER TABLE guardia_cover DROP absence_id, DROP task_document_path, DROP task_document_name, DROP task_description, CHANGE group_name group_name VARCHAR(64) DEFAULT NULL');

        $this->addSql('ALTER TABLE guardia_absence DROP FOREIGN KEY FK_guardia_absence_teacher');
        $this->addSql('DROP TABLE guardia_absence');
    }
}
