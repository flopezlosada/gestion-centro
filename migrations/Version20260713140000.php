<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Task responsibility, phase 0 (additive, behaviour-preserving): adds delegated_to_id (explicit
 * delegation override) and completed_by_id (who actually did it, frozen at close). Backfills
 * completed_by_id from the assignee for tasks already in the terminal "validated" state.
 */
final class Version20260713140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Task: delegated_to_id + completed_by_id (delegación y congelado de histórico).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task ADD delegated_to_id INT DEFAULT NULL, ADD completed_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT FK_task_delegated_to FOREIGN KEY (delegated_to_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT FK_task_completed_by FOREIGN KEY (completed_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_task_delegated_to ON task (delegated_to_id)');
        $this->addSql('CREATE INDEX IDX_task_completed_by ON task (completed_by_id)');
        // Backfill: a closed task was done by its assignee (the authoritative source of "who did it" today).
        $this->addSql("UPDATE task SET completed_by_id = assigned_user_id WHERE status = 'validated'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task DROP FOREIGN KEY FK_task_delegated_to');
        $this->addSql('ALTER TABLE task DROP FOREIGN KEY FK_task_completed_by');
        $this->addSql('DROP INDEX IDX_task_delegated_to ON task');
        $this->addSql('DROP INDEX IDX_task_completed_by ON task');
        $this->addSql('ALTER TABLE task DROP delegated_to_id, DROP completed_by_id');
    }
}
