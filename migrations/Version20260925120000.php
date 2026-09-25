<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The data-protection information the staff read before using the application: its published versions
 * (immutable, the latest is the one in force) and who read which version, when. No rows are created
 * here: until the centre publishes a first version, nobody is asked anything.
 */
final class Version20260925120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Data-protection information: published versions (privacy_notice) and who read each one (privacy_notice_ack).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE privacy_notice (id INT AUTO_INCREMENT NOT NULL, summary LONGTEXT NOT NULL, body LONGTEXT NOT NULL, published_at DATETIME NOT NULL, published_by_id INT DEFAULT NULL, INDEX IDX_8696E8CF5B075477 (published_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE privacy_notice_ack (id INT AUTO_INCREMENT NOT NULL, acknowledged_at DATETIME NOT NULL, user_id INT NOT NULL, notice_id INT NOT NULL, INDEX IDX_C590958AA76ED395 (user_id), INDEX IDX_C590958A7D540AB (notice_id), UNIQUE INDEX uniq_privacy_notice_ack (user_id, notice_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE privacy_notice ADD CONSTRAINT FK_8696E8CF5B075477 FOREIGN KEY (published_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE privacy_notice_ack ADD CONSTRAINT FK_C590958AA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE privacy_notice_ack ADD CONSTRAINT FK_C590958A7D540AB FOREIGN KEY (notice_id) REFERENCES privacy_notice (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE privacy_notice DROP FOREIGN KEY FK_8696E8CF5B075477');
        $this->addSql('ALTER TABLE privacy_notice_ack DROP FOREIGN KEY FK_C590958AA76ED395');
        $this->addSql('ALTER TABLE privacy_notice_ack DROP FOREIGN KEY FK_C590958A7D540AB');
        $this->addSql('DROP TABLE privacy_notice_ack');
        $this->addSql('DROP TABLE privacy_notice');
    }
}
