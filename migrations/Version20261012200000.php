<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The queue of shares of the actas in the EducaMadrid cloud (App\Entity\CloudShare): the cloud lets an
 * account create only 20 shares every 10 minutes, so what does not fit waits here for the scheduled task.
 */
final class Version20261012200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the queue of acta shares in the EducaMadrid cloud.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cloud_share (id INT AUTO_INCREMENT NOT NULL, file_id VARCHAR(32) NOT NULL, permissions SMALLINT NOT NULL, notify TINYINT NOT NULL, problem VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, meeting_id INT NOT NULL, person_id INT NOT NULL, INDEX IDX_A505CC3967433D9C (meeting_id), INDEX IDX_A505CC39217BBB47 (person_id), UNIQUE INDEX uniq_cloud_share_file_person (file_id, person_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE cloud_share ADD CONSTRAINT FK_A505CC3967433D9C FOREIGN KEY (meeting_id) REFERENCES meeting (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE cloud_share ADD CONSTRAINT FK_A505CC39217BBB47 FOREIGN KEY (person_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cloud_share');
    }
}
