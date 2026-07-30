<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Banco de tareas de guardia y encargos de fotocopias (petición del centro del 30-07-2026).
 *
 * - guardia_task_bank: el trabajo que cada departamento deja preparado por curso + nivel + materia (la
 *   materia es obligatoria: el grupo trabaja la asignatura que le tocaba) y, opcionalmente, para unas
 *   letras de grupo concretas. Lleva documento y/o descripción, las copias que suele necesitar y un
 *   contador de usos. La baja es lógica (active), para que una guardia que ya la usó siga contando qué
 *   se le dio al grupo; y va atada al curso porque el centro vacía el banco cada septiembre.
 * - guardia_cover.subject_name / copies_needed: la materia que se pierde (para casar con el banco) y las
 *   copias que hagan falta, que puede dejar dichas el profe ausente al apuntar la falta.
 * - guardia_cover.bank_item_id: la tarea del banco que se le dio a ese grupo cuando el profesor ausente
 *   no dejó nada. Referencia, no copia (ON DELETE SET NULL: si se borra del banco, la guardia deja de
 *   apuntarla pero no se rompe).
 * - copy_request: el encargo enviado al correo de conserjería, con el número de copias (obligatorio),
 *   el documento que viajó adjunto y cuándo salió el correo (sent_at nulo = pendiente de reenvío).
 *
 * Sin backfill: las tres cosas nacen vacías. Nada de lo existente cambia de forma.
 */
final class Version20260730120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Banco de tareas de guardia por curso/nivel/materia y encargos de fotocopias a conserjería.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE guardia_task_bank (
            id INT AUTO_INCREMENT NOT NULL,
            department_id INT NOT NULL,
            created_by_id INT DEFAULT NULL,
            academic_year_id INT NOT NULL,
            level VARCHAR(16) NOT NULL,
            subject VARCHAR(128) NOT NULL,
            sections VARCHAR(64) DEFAULT NULL,
            title VARCHAR(160) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            document_path VARCHAR(255) DEFAULT NULL,
            document_name VARCHAR(255) DEFAULT NULL,
            suggested_copies SMALLINT DEFAULT NULL,
            active TINYINT(1) NOT NULL,
            times_used INT DEFAULT 0 NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX IDX_bank_year_level_active (academic_year_id, level, active),
            INDEX IDX_bank_department (department_id),
            INDEX IDX_bank_created_by (created_by_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4');
        // El departamento responde de la tarea: no se borra en cascada (los departamentos se retiran,
        // no se borran) ni se deja huérfana; borrar uno con tareas en el banco falla a propósito.
        $this->addSql('ALTER TABLE guardia_task_bank ADD CONSTRAINT FK_bank_department FOREIGN KEY (department_id) REFERENCES org_unit (id)');
        $this->addSql('ALTER TABLE guardia_task_bank ADD CONSTRAINT FK_bank_created_by FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        // Borrar un curso se lleva su banco: son tareas de ESE curso, como el horario.
        $this->addSql('ALTER TABLE guardia_task_bank ADD CONSTRAINT FK_bank_year FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE CASCADE');

        // La materia se rellena en las ausencias NUEVAS (se toma del horario al apuntarlas); las líneas
        // ya existentes se quedan a NULL a propósito: reconstruirla hoy sería adivinar qué daba ese
        // profesor aquel día, y una materia inventada mandaría al grupo una tarea equivocada.
        $this->addSql('ALTER TABLE guardia_cover ADD bank_item_id INT DEFAULT NULL, ADD subject_name VARCHAR(128) DEFAULT NULL, ADD copies_needed SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE guardia_cover ADD CONSTRAINT FK_guardia_cover_bank_item FOREIGN KEY (bank_item_id) REFERENCES guardia_task_bank (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_guardia_cover_bank_item ON guardia_cover (bank_item_id)');

        $this->addSql('CREATE TABLE copy_request (
            id INT AUTO_INCREMENT NOT NULL,
            cover_id INT DEFAULT NULL,
            bank_item_id INT DEFAULT NULL,
            requested_by_id INT DEFAULT NULL,
            copies SMALLINT NOT NULL,
            notes LONGTEXT DEFAULT NULL,
            document_path VARCHAR(255) DEFAULT NULL,
            document_name VARCHAR(255) DEFAULT NULL,
            context VARCHAR(255) NOT NULL,
            recipient VARCHAR(180) NOT NULL,
            requested_at DATETIME NOT NULL,
            sent_at DATETIME DEFAULT NULL,
            INDEX IDX_copy_requested_at (requested_at),
            INDEX IDX_copy_cover (cover_id),
            INDEX IDX_copy_bank_item (bank_item_id),
            INDEX IDX_copy_requested_by (requested_by_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4');
        // El encargo sobrevive a lo que lo originó: es el registro de lo que se mandó a conserjería.
        $this->addSql('ALTER TABLE copy_request ADD CONSTRAINT FK_copy_cover FOREIGN KEY (cover_id) REFERENCES guardia_cover (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE copy_request ADD CONSTRAINT FK_copy_bank_item FOREIGN KEY (bank_item_id) REFERENCES guardia_task_bank (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE copy_request ADD CONSTRAINT FK_copy_requested_by FOREIGN KEY (requested_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE copy_request DROP FOREIGN KEY FK_copy_cover');
        $this->addSql('ALTER TABLE copy_request DROP FOREIGN KEY FK_copy_bank_item');
        $this->addSql('ALTER TABLE copy_request DROP FOREIGN KEY FK_copy_requested_by');
        $this->addSql('DROP TABLE copy_request');

        $this->addSql('ALTER TABLE guardia_cover DROP FOREIGN KEY FK_guardia_cover_bank_item');
        $this->addSql('DROP INDEX IDX_guardia_cover_bank_item ON guardia_cover');
        $this->addSql('ALTER TABLE guardia_cover DROP bank_item_id, DROP subject_name, DROP copies_needed');

        $this->addSql('ALTER TABLE guardia_task_bank DROP FOREIGN KEY FK_bank_department');
        $this->addSql('ALTER TABLE guardia_task_bank DROP FOREIGN KEY FK_bank_created_by');
        $this->addSql('ALTER TABLE guardia_task_bank DROP FOREIGN KEY FK_bank_year');
        $this->addSql('DROP TABLE guardia_task_bank');
    }
}
