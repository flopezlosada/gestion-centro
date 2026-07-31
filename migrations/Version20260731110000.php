<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Entrega de tareas: qué hay que aportar, el archivo adjunto, el estado "En revisión" y los comentarios.
 *
 * Cuatro cosas que el centro pidió juntas y que son el mismo ciclo:
 *
 *  1. `deliverable_requirement` sustituye al booleano `requires_document`, que solo sabía decir "pega un
 *     enlace". Ahora se elige enlace, archivo, cualquiera de los dos o nada. Las tareas que ya exigían
 *     documento pasan a 'link', que es EXACTAMENTE lo que se les podía pedir hasta hoy: nadie se
 *     encuentra de pronto con que le exigen un fichero que no tiene.
 *  2. `deliverable_file_path`/`_name` guardan el archivo entregado (en almacenamiento privado, como las
 *     actas), para lo que no vive en la nube del centro: un impreso escaneado, una hoja firmada.
 *  3. El estado 'in_review'. No hace falta convertir ninguna fila: es un sitio nuevo al que solo se llega
 *     devolviendo una tarea entregada, y hasta ahora "devolver" dejaba la tarea en 'pending', que sigue
 *     siendo un estado válido.
 *  4. `task_comment`, el hilo de la tarea. Vacío al empezar: lo que se dijo antes no existía en ningún
 *     sitio del que rescatarlo.
 */
final class Version20260731110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tareas: qué exige la entrega, archivo adjunto y comentarios.';
    }

    public function up(Schema $schema): void
    {
        foreach (['task', 'task_template'] as $table) {
            $this->addSql(sprintf("ALTER TABLE %s ADD deliverable_requirement VARCHAR(10) DEFAULT 'none' NOT NULL", $table));
            $this->addSql(sprintf("UPDATE %s SET deliverable_requirement = 'link' WHERE requires_document = 1", $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP requires_document', $table));
        }

        $this->addSql('ALTER TABLE task ADD deliverable_file_path VARCHAR(255) DEFAULT NULL, ADD deliverable_file_name VARCHAR(255) DEFAULT NULL');

        $this->addSql('CREATE TABLE task_comment (
            id INT AUTO_INCREMENT NOT NULL,
            task_id INT NOT NULL,
            author_id INT DEFAULT NULL,
            body LONGTEXT NOT NULL,
            transition VARCHAR(20) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_task_comment_task (task_id, created_at),
            INDEX IDX_task_comment_author (author_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4');
        // El hilo no tiene sentido sin su tarea (CASCADE); el autor sí puede irse del centro sin llevarse
        // lo que escribió (SET NULL), igual que en el resto de la aplicación.
        $this->addSql('ALTER TABLE task_comment ADD CONSTRAINT FK_task_comment_task FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_comment ADD CONSTRAINT FK_task_comment_author FOREIGN KEY (author_id) REFERENCES app_user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE task_comment');
        $this->addSql('ALTER TABLE task DROP deliverable_file_path, DROP deliverable_file_name');

        foreach (['task', 'task_template'] as $table) {
            $this->addSql(sprintf('ALTER TABLE %s ADD requires_document TINYINT(1) DEFAULT 0 NOT NULL', $table));
            $this->addSql(sprintf("UPDATE %s SET requires_document = 1 WHERE deliverable_requirement <> 'none'", $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP deliverable_requirement', $table));
        }

        // Lo devuelto para revisar vuelve a Pendiente, que es donde caía antes de que existiera el estado.
        $this->addSql("UPDATE task SET status = 'pending' WHERE status = 'in_review'");
    }
}
