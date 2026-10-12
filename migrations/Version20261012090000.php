<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Where a meeting's acta lives when it is written in the centre's EducaMadrid cloud: the document's file
 * id there (what a link is built from) and the id of the frozen copy the cloud makes at each publication
 * (what the people convened read).
 */
final class Version20261012090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add meeting.minutes_cloud_file_id and minutes_cloud_published_file_id for actas kept in the EducaMadrid cloud.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE meeting ADD minutes_cloud_file_id VARCHAR(32) DEFAULT NULL, ADD minutes_cloud_published_file_id VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE meeting DROP minutes_cloud_file_id, DROP minutes_cloud_published_file_id');
    }
}
