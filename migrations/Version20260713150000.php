<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Task responsibility, phase 1: the task_responsibility table (single-table inheritance:
 * cargo/person/role) and task.responsibility_id (1:1). Backfills every existing task to a
 * PersonResponsibility on its current assignee — the conservative, behaviour-preserving translation
 * of today's model (assignedUser is the authoritative owner today). The old assigned_role_id /
 * assigned_user_id columns stay in place as a fallback during the transition (dropped in a later phase).
 */
final class Version20260713150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Task responsibility STI (cargo/person/role) + backfill conservador a persona.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE task_responsibility (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(20) NOT NULL, unit_id INT DEFAULT NULL, user_id INT DEFAULT NULL, role_id INT DEFAULT NULL, INDEX IDX_task_resp_unit (unit_id), INDEX IDX_task_resp_user (user_id), INDEX IDX_task_resp_role (role_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE task_responsibility ADD CONSTRAINT FK_task_resp_unit FOREIGN KEY (unit_id) REFERENCES org_unit (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task_responsibility ADD CONSTRAINT FK_task_resp_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task_responsibility ADD CONSTRAINT FK_task_resp_role FOREIGN KEY (role_id) REFERENCES role (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE task ADD responsibility_id INT DEFAULT NULL');

        // Conservative backfill, set-based (no per-row lastInsertId loop): a temporary column carries
        // the source task id so we can link each new PersonResponsibility back to its task, then drop it.
        $this->addSql('ALTER TABLE task_responsibility ADD source_task_id INT DEFAULT NULL');
        $this->addSql("INSERT INTO task_responsibility (kind, user_id, source_task_id) SELECT 'person', assigned_user_id, id FROM task WHERE assigned_user_id IS NOT NULL");
        $this->addSql('UPDATE task t JOIN task_responsibility tr ON tr.source_task_id = t.id SET t.responsibility_id = tr.id');
        $this->addSql('ALTER TABLE task_responsibility DROP source_task_id');

        $this->addSql('ALTER TABLE task ADD CONSTRAINT FK_task_responsibility FOREIGN KEY (responsibility_id) REFERENCES task_responsibility (id) ON DELETE SET NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_task_responsibility ON task (responsibility_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task DROP FOREIGN KEY FK_task_responsibility');
        $this->addSql('DROP INDEX UNIQ_task_responsibility ON task');
        $this->addSql('ALTER TABLE task DROP responsibility_id');
        $this->addSql('ALTER TABLE task_responsibility DROP FOREIGN KEY FK_task_resp_unit');
        $this->addSql('ALTER TABLE task_responsibility DROP FOREIGN KEY FK_task_resp_user');
        $this->addSql('ALTER TABLE task_responsibility DROP FOREIGN KEY FK_task_resp_role');
        $this->addSql('DROP TABLE task_responsibility');
    }
}
