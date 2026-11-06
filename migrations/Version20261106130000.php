<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `task_reminder_schedule`: cuándo quiere cada persona que se le recuerde cada tarea suya (antelación
 * y frecuencia). Sin fila, los avisos de siempre (15 y 7 días antes), así que no hay nada que rellenar.
 */
final class Version20261106130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tareas: avisos elegidos por cada persona (antelación y frecuencia).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE task_reminder_schedule (id INT AUTO_INCREMENT NOT NULL, task_id INT NOT NULL, user_id INT NOT NULL, start_days SMALLINT NOT NULL, repeat_days SMALLINT NOT NULL, INDEX IDX_D1B84A308DB60186 (task_id), INDEX IDX_D1B84A30A76ED395 (user_id), UNIQUE INDEX uniq_task_reminder_schedule (task_id, user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE task_reminder_schedule ADD CONSTRAINT FK_TRS_TASK FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_reminder_schedule ADD CONSTRAINT FK_TRS_USER FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE task_reminder_schedule');
    }
}
