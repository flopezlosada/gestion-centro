<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Task responsibility as "role + (department)": a task_responsibility row is a role and, when the role
 * is per-department, the department; the responsible people are resolved live from the role's holders.
 * Adds role.per_department (which roles are scoped to a department) and task.responsibility_id.
 */
final class Version20260713150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Responsabilidad de tarea = rol + (departamento); role.per_department.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE role ADD per_department TINYINT(1) DEFAULT 0 NOT NULL');

        $this->addSql('CREATE TABLE task_responsibility (id INT AUTO_INCREMENT NOT NULL, role_id INT NOT NULL, unit_id INT DEFAULT NULL, INDEX IDX_task_resp_role (role_id), INDEX IDX_task_resp_unit (unit_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE task_responsibility ADD CONSTRAINT FK_task_resp_role FOREIGN KEY (role_id) REFERENCES role (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_responsibility ADD CONSTRAINT FK_task_resp_unit FOREIGN KEY (unit_id) REFERENCES org_unit (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE task ADD responsibility_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT FK_task_responsibility FOREIGN KEY (responsibility_id) REFERENCES task_responsibility (id) ON DELETE SET NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_task_responsibility ON task (responsibility_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task DROP FOREIGN KEY FK_task_responsibility');
        $this->addSql('DROP INDEX UNIQ_task_responsibility ON task');
        $this->addSql('ALTER TABLE task DROP responsibility_id');
        $this->addSql('ALTER TABLE task_responsibility DROP FOREIGN KEY FK_task_resp_role');
        $this->addSql('ALTER TABLE task_responsibility DROP FOREIGN KEY FK_task_resp_unit');
        $this->addSql('DROP TABLE task_responsibility');
        $this->addSql('ALTER TABLE role DROP per_department');
    }
}
