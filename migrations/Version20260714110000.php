<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops the org_unit tree/manager: parent_id, manager_id and is_department. The chain of command is
 * now derived entirely from the ranked roles people hold ({@see \App\Service\OrganizationHierarchy}),
 * so a unit is just a department with no parent, no manager and no department flag (every unit is a
 * department). Only the org_unit columns are touched — custom index/FK names elsewhere are intentional.
 */
final class Version20260714110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'org_unit: quitar parent_id, manager_id e is_department (jerarquía por roles).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE org_unit DROP FOREIGN KEY FK_455318CB727ACA70');
        $this->addSql('ALTER TABLE org_unit DROP FOREIGN KEY FK_455318CB783E3463');
        $this->addSql('DROP INDEX IDX_455318CB727ACA70 ON org_unit');
        $this->addSql('DROP INDEX IDX_455318CB783E3463 ON org_unit');
        $this->addSql('ALTER TABLE org_unit DROP parent_id, DROP manager_id, DROP is_department');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE org_unit ADD parent_id INT DEFAULT NULL, ADD manager_id INT DEFAULT NULL, ADD is_department TINYINT(1) DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE org_unit ADD CONSTRAINT FK_455318CB727ACA70 FOREIGN KEY (parent_id) REFERENCES org_unit (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE org_unit ADD CONSTRAINT FK_455318CB783E3463 FOREIGN KEY (manager_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_455318CB727ACA70 ON org_unit (parent_id)');
        $this->addSql('CREATE INDEX IDX_455318CB783E3463 ON org_unit (manager_id)');
    }
}
