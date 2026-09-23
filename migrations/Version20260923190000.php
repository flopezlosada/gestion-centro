<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A class ordinarily works on one topic, but a class that closes one and opens another is common enough
 * to count for something: `lesson_plan_topic` replaces `lesson_plan`'s single topic/activity/outcome with
 * up to two ordered entries per class ({@see \App\Entity\LessonPlanTopic}).
 *
 * Every existing plan's topic, activity and outcome move to a single entry at position 0 before the old
 * columns are dropped — nothing already recorded is lost.
 */
final class Version20260923190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A class plan can now have two topic entries: the one it closed and the one it opened.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE lesson_plan_topic (id INT AUTO_INCREMENT NOT NULL, activity VARCHAR(16) DEFAULT NULL, outcome VARCHAR(16) DEFAULT NULL, position SMALLINT NOT NULL, lesson_plan_id INT NOT NULL, topic_id INT DEFAULT NULL, INDEX IDX_C3EFD2361DE5503C (lesson_plan_id), INDEX IDX_C3EFD2361F55203D (topic_id), UNIQUE INDEX uniq_lesson_plan_topic_position (lesson_plan_id, position), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE lesson_plan_topic ADD CONSTRAINT FK_C3EFD2361DE5503C FOREIGN KEY (lesson_plan_id) REFERENCES lesson_plan (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE lesson_plan_topic ADD CONSTRAINT FK_C3EFD2361F55203D FOREIGN KEY (topic_id) REFERENCES topic (id) ON DELETE SET NULL');

        $this->addSql('INSERT INTO lesson_plan_topic (lesson_plan_id, topic_id, activity, outcome, position) SELECT id, topic_id, activity, outcome, 0 FROM lesson_plan WHERE topic_id IS NOT NULL OR activity IS NOT NULL OR outcome IS NOT NULL');

        $this->addSql('ALTER TABLE lesson_plan DROP FOREIGN KEY FK_E42B9D191F55203D');
        $this->addSql('DROP INDEX IDX_E42B9D191F55203D ON lesson_plan');
        $this->addSql('ALTER TABLE lesson_plan DROP activity, DROP outcome, DROP topic_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE lesson_plan ADD activity VARCHAR(16) DEFAULT NULL, ADD outcome VARCHAR(16) DEFAULT NULL, ADD topic_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE lesson_plan ADD CONSTRAINT FK_E42B9D191F55203D FOREIGN KEY (topic_id) REFERENCES topic (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_E42B9D191F55203D ON lesson_plan (topic_id)');

        $this->addSql('UPDATE lesson_plan p JOIN lesson_plan_topic lt ON lt.lesson_plan_id = p.id AND lt.position = 0 SET p.topic_id = lt.topic_id, p.activity = lt.activity, p.outcome = lt.outcome');

        $this->addSql('ALTER TABLE lesson_plan_topic DROP FOREIGN KEY FK_C3EFD2361DE5503C');
        $this->addSql('ALTER TABLE lesson_plan_topic DROP FOREIGN KEY FK_C3EFD2361F55203D');
        $this->addSql('DROP TABLE lesson_plan_topic');
    }
}
