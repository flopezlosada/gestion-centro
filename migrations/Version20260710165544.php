<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260710165544 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE org_unit (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(50) NOT NULL, name VARCHAR(120) NOT NULL, description LONGTEXT DEFAULT NULL, parent_id INT DEFAULT NULL, manager_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_455318CB77153098 (code), INDEX IDX_455318CB727ACA70 (parent_id), INDEX IDX_455318CB783E3463 (manager_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE task (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(200) NOT NULL, description LONGTEXT DEFAULT NULL, type VARCHAR(30) NOT NULL, school_year VARCHAR(9) NOT NULL, due_date DATE NOT NULL, mandatory TINYINT NOT NULL, status VARCHAR(30) NOT NULL, requires_document TINYINT NOT NULL, requires_checkbox TINYINT NOT NULL, checkbox_done TINYINT NOT NULL, deliverable_reference VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, template_id INT DEFAULT NULL, assigned_role_id INT DEFAULT NULL, assigned_user_id INT DEFAULT NULL, unit_id INT DEFAULT NULL, INDEX IDX_527EDB255DA0FB8 (template_id), INDEX IDX_527EDB25DC9B9A23 (assigned_role_id), INDEX IDX_527EDB25ADF66B1A (assigned_user_id), INDEX IDX_527EDB25F8BD700D (unit_id), INDEX idx_task_year_due (school_year, due_date), INDEX idx_task_status (status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE task_template (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(200) NOT NULL, description LONGTEXT DEFAULT NULL, type VARCHAR(30) NOT NULL, mandatory TINYINT NOT NULL, requires_document TINYINT NOT NULL, requires_checkbox TINYINT NOT NULL, active TINYINT NOT NULL, responsible_role_id INT DEFAULT NULL, INDEX IDX_D7A0F5CFCCC0E8A1 (responsible_role_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE org_unit ADD CONSTRAINT FK_455318CB727ACA70 FOREIGN KEY (parent_id) REFERENCES org_unit (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE org_unit ADD CONSTRAINT FK_455318CB783E3463 FOREIGN KEY (manager_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT FK_527EDB255DA0FB8 FOREIGN KEY (template_id) REFERENCES task_template (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT FK_527EDB25DC9B9A23 FOREIGN KEY (assigned_role_id) REFERENCES role (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT FK_527EDB25ADF66B1A FOREIGN KEY (assigned_user_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT FK_527EDB25F8BD700D FOREIGN KEY (unit_id) REFERENCES org_unit (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE task_template ADD CONSTRAINT FK_D7A0F5CFCCC0E8A1 FOREIGN KEY (responsible_role_id) REFERENCES role (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE app_user ADD unit_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD CONSTRAINT FK_88BDF3E9F8BD700D FOREIGN KEY (unit_id) REFERENCES org_unit (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_88BDF3E9F8BD700D ON app_user (unit_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE org_unit DROP FOREIGN KEY FK_455318CB727ACA70');
        $this->addSql('ALTER TABLE org_unit DROP FOREIGN KEY FK_455318CB783E3463');
        $this->addSql('ALTER TABLE task DROP FOREIGN KEY FK_527EDB255DA0FB8');
        $this->addSql('ALTER TABLE task DROP FOREIGN KEY FK_527EDB25DC9B9A23');
        $this->addSql('ALTER TABLE task DROP FOREIGN KEY FK_527EDB25ADF66B1A');
        $this->addSql('ALTER TABLE task DROP FOREIGN KEY FK_527EDB25F8BD700D');
        $this->addSql('ALTER TABLE task_template DROP FOREIGN KEY FK_D7A0F5CFCCC0E8A1');
        $this->addSql('DROP TABLE org_unit');
        $this->addSql('DROP TABLE task');
        $this->addSql('DROP TABLE task_template');
        $this->addSql('ALTER TABLE app_user DROP FOREIGN KEY FK_88BDF3E9F8BD700D');
        $this->addSql('DROP INDEX IDX_88BDF3E9F8BD700D ON app_user');
        $this->addSql('ALTER TABLE app_user DROP unit_id');
    }
}
