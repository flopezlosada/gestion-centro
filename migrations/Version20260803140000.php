<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Una sola forma de decir de quién es una tarea: se retira `task.assigned_role_id`.
 *
 * Convivían dos. La buena, `task_responsibility` (rol + departamento, resuelta en vivo), y una columna
 * suelta que quedó de antes y que solo escribía ya la generación anual desde el catálogo
 * ({@see \App\Entity\Task::fromTemplate()}). No era un duplicado inofensivo: `OrganizationHierarchy`
 * lee ÚNICAMENTE la responsabilidad, así que una tarea nacida de una plantilla no tenía a nadie por
 * encima — ni quien la validara ni a quién escalarla — y en pantalla no se distinguía de las demás.
 *
 * El backfill va antes del DROP y es lo que hace que esto no pierda nada: cada fila que aún tenga rol
 * suelto y no tenga responsabilidad estrena una, con el mismo rol y con el departamento que la propia
 * tarea ya llevaba en `unit_id` — que es exactamente la pareja (rol, departamento) que
 * `TaskResponsibility` modela. En la base local esto afecta a CERO filas (la columna está vacía en las
 * 127 tareas), pero en staging y en producción no se sabe hasta correrlo, y una migración que da por
 * hecho que la columna está vacía es la que borra el dato de alguien.
 *
 * El rastro de auditoría NO se toca: sus filas siguen nombrando el campo `assignedRole` y así se
 * quedan, porque el histórico cuenta lo que pasó y no lo que hoy es verdad
 * ({@see \App\Support\TaskActivityPresenter}).
 */
final class Version20260803140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Retira task.assigned_role_id tras pasar sus filas a task_responsibility.';
    }

    public function up(Schema $schema): void
    {
        // Una columna temporal que recuerde de qué tarea salió cada responsabilidad. Sin ella habría que
        // volver a casarlas por (rol, departamento) después de insertarlas, y ahí está la trampa:
        // veinte tareas de "jefatura de departamento de Matemáticas" generan veinte filas idénticas, un
        // JOIN por esa pareja casa cada tarea con las veinte y MySQL engancha una cualquiera — con lo
        // que varias tareas acabarían COMPARTIENDO una responsabilidad que el modelo declara 1:1
        // (OneToOne con orphanRemoval), y borrar una tarea dejaría a las otras sin responsable. Con la
        // columna, cada fila sabe a quién pertenece y el enganche es exacto.
        //
        // `IF NOT EXISTS` porque el DDL en MariaDB hace COMMIT implícito y se sale de la transacción
        // que envuelve la migración: si el INSERT de abajo fallara con datos que el clon de pruebas no
        // tenía, la columna se quedaría creada, la migración figuraría como NO ejecutada, y el
        // reintento moriría en este mismo ALTER por columna duplicada — arreglable solo a mano en la
        // base de producción. Con la guarda, el reintento sigue adelante. Comprobado en MariaDB 10.11.
        $this->addSql('ALTER TABLE task_responsibility ADD COLUMN IF NOT EXISTS source_task_id INT DEFAULT NULL');

        // Una responsabilidad por cada tarea que solo tenía el rol suelto. El departamento sale de la
        // propia tarea: para un rol de centro es NULL, y para uno de departamento es el suyo.
        $this->addSql('INSERT INTO task_responsibility (role_id, unit_id, source_task_id)
            SELECT t.assigned_role_id, t.unit_id, t.id
            FROM task t
            WHERE t.assigned_role_id IS NOT NULL AND t.responsibility_id IS NULL');

        $this->addSql('UPDATE task t
            JOIN task_responsibility r ON r.source_task_id = t.id
            SET t.responsibility_id = r.id
            WHERE t.responsibility_id IS NULL');

        $this->addSql('ALTER TABLE task_responsibility DROP COLUMN IF EXISTS source_task_id');

        $this->addSql('ALTER TABLE task DROP FOREIGN KEY FK_527EDB25DC9B9A23');
        $this->addSql('DROP INDEX IDX_527EDB25DC9B9A23 ON task');
        $this->addSql('ALTER TABLE task DROP assigned_role_id');
    }

    public function down(Schema $schema): void
    {
        // Se devuelve la columna y se rellena desde la responsabilidad, que es de donde salió. No queda
        // idéntica a la de antes: las tareas que YA tenían responsabilidad y nunca tuvieron rol suelto
        // pasan a tenerlo. Es lo correcto para un rollback (la columna vuelve a describir la
        // responsabilidad real), pero no es una restauración byte a byte.
        $this->addSql('ALTER TABLE task ADD assigned_role_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT FK_527EDB25DC9B9A23 FOREIGN KEY (assigned_role_id) REFERENCES role (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_527EDB25DC9B9A23 ON task (assigned_role_id)');
        $this->addSql('UPDATE task t JOIN task_responsibility r ON r.id = t.responsibility_id SET t.assigned_role_id = r.role_id');
    }
}
