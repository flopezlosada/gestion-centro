<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260712105051 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Regla de fecha opcional en las plantillas (task_template.due_date_rule, JSON).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_template ADD due_date_rule JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_template DROP due_date_rule');
    }
}
