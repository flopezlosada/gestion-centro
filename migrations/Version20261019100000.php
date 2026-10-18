<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Actividades con familias semanales en clases de un grupo, e inscripciones por sesión (con fecha)';
    }

    public function up(Schema $schema): void
    {
        // Every activity so far is a one-off: the kind is added filled in, then left without a default, as mapped.
        $this->addSql("ALTER TABLE family_activity ADD kind VARCHAR(16) NOT NULL DEFAULT 'one_off', ADD school_year VARCHAR(9) DEFAULT NULL, ADD group_names VARCHAR(160) DEFAULT NULL, ADD class_times JSON DEFAULT NULL, CHANGE start_at start_at DATETIME DEFAULT NULL");
        $this->addSql('ALTER TABLE family_activity ALTER kind DROP DEFAULT');

        // A signup so far is for the one session of its one-off activity: its day.
        $this->addSql('ALTER TABLE family_signup ADD session_date DATE DEFAULT NULL');
        $this->addSql('UPDATE family_signup s JOIN family_activity a ON a.id = s.activity_id SET s.session_date = DATE(a.start_at)');
        $this->addSql('ALTER TABLE family_signup CHANGE session_date session_date DATE NOT NULL');
        $this->addSql('DROP INDEX uniq_family_signup ON family_signup');
        $this->addSql('CREATE UNIQUE INDEX uniq_family_signup_session ON family_signup (activity_id, family_id, session_date)');
    }

    public function down(Schema $schema): void
    {
        // Back to one signup per activity and family: the weekly activities, and the signups of any session
        // but one per family, cannot be kept.
        $this->addSql("DELETE FROM family_activity WHERE kind = 'weekly'");
        $this->addSql('DELETE s FROM family_signup s JOIN family_signup t ON t.activity_id = s.activity_id AND t.family_id = s.family_id AND t.id < s.id');
        $this->addSql('DROP INDEX uniq_family_signup_session ON family_signup');
        $this->addSql('CREATE UNIQUE INDEX uniq_family_signup ON family_signup (activity_id, family_id)');
        $this->addSql('ALTER TABLE family_signup DROP session_date');
        $this->addSql('ALTER TABLE family_activity DROP kind, DROP school_year, DROP group_names, DROP class_times, CHANGE start_at start_at DATETIME NOT NULL');
    }
}
