<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260710190950 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE org_unit ADD active TINYINT NOT NULL');
        $this->addSql('DROP INDEX idx_task_status ON task');
        $this->addSql('CREATE INDEX idx_task_due_status ON task (due_date, status)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE org_unit DROP active');
        $this->addSql('DROP INDEX idx_task_due_status ON task');
        $this->addSql('CREATE INDEX idx_task_status ON task (status)');
    }
}
