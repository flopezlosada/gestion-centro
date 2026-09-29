<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The link to the video call of a meeting held online. Nullable: every existing meeting is in person.
 */
final class Version20260929180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Meeting: optional link to the video call (online_url).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE meeting ADD online_url VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE meeting DROP online_url');
    }
}
