<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A task can carry what is needed to do it: a link and/or a file, set by whoever creates it. All
 * nullable: no existing task has either.
 */
final class Version20260929220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Task: optional information link and file (info_url, info_file_path, info_file_name).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task ADD info_url VARCHAR(500) DEFAULT NULL, ADD info_file_path VARCHAR(255) DEFAULT NULL, ADD info_file_name VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task DROP info_url, DROP info_file_path, DROP info_file_name');
    }
}
