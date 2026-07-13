<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Event categories become an admin-managed catalogue: a new event_category table (seeded with the
 * former fixed set), and personal_event.category moves from an enum column to a nullable FK. Existing
 * events are migrated from their old enum value to the matching seeded category.
 */
final class Version20260713110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Categorías de evento gestionables: tabla event_category + FK personal_event.category_id (migra el enum a entidad).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE event_category (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(50) NOT NULL, color VARCHAR(20) NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        // Seed the default catalogue, keeping the colours the previous fixed-enum version used.
        $this->addSql("INSERT INTO event_category (name, color) VALUES ('General', 'slate'), ('Docencia', 'blue'), ('Reunión', 'teal'), ('Tutoría', 'green'), ('Personal', 'amber')");

        $this->addSql('ALTER TABLE personal_event ADD category_id INT DEFAULT NULL');
        // Migrate existing events from the old enum value to the matching seeded category.
        $this->addSql("UPDATE personal_event pe JOIN event_category ec ON ec.name = 'General'  SET pe.category_id = ec.id WHERE pe.category = 'general'");
        $this->addSql("UPDATE personal_event pe JOIN event_category ec ON ec.name = 'Docencia' SET pe.category_id = ec.id WHERE pe.category = 'teaching'");
        $this->addSql("UPDATE personal_event pe JOIN event_category ec ON ec.name = 'Reunión'  SET pe.category_id = ec.id WHERE pe.category = 'meeting'");
        $this->addSql("UPDATE personal_event pe JOIN event_category ec ON ec.name = 'Tutoría'  SET pe.category_id = ec.id WHERE pe.category = 'tutoring'");
        $this->addSql("UPDATE personal_event pe JOIN event_category ec ON ec.name = 'Personal' SET pe.category_id = ec.id WHERE pe.category = 'personal'");
        $this->addSql('ALTER TABLE personal_event DROP category');

        $this->addSql('ALTER TABLE personal_event ADD CONSTRAINT FK_63F74CB112469DE2 FOREIGN KEY (category_id) REFERENCES event_category (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_63F74CB112469DE2 ON personal_event (category_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE personal_event DROP FOREIGN KEY FK_63F74CB112469DE2');
        $this->addSql('DROP INDEX IDX_63F74CB112469DE2 ON personal_event');
        $this->addSql("ALTER TABLE personal_event ADD category VARCHAR(20) DEFAULT 'general' NOT NULL");
        $this->addSql('ALTER TABLE personal_event DROP category_id');
        $this->addSql('DROP TABLE event_category');
    }
}
