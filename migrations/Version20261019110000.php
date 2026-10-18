<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Actividades con familias semanales en varias clases de un grupo (class_times), no en una sola';
    }

    public function up(Schema $schema): void
    {
        // Each weekly activity so far is held in its one class: that class becomes the only one of its list.
        $this->addSql('ALTER TABLE family_activity ADD class_times JSON DEFAULT NULL');
        $this->addSql("UPDATE family_activity SET class_times = JSON_ARRAY(JSON_OBJECT('weekday', weekday, 'slot', slot_index, 'subject', subject)) WHERE kind = 'weekly'");
        $this->addSql('ALTER TABLE family_activity DROP weekday, DROP slot_index, DROP subject');
    }

    public function down(Schema $schema): void
    {
        // Back to one class per activity: the first of its list is kept, the others are lost.
        $this->addSql('ALTER TABLE family_activity ADD weekday SMALLINT DEFAULT NULL, ADD slot_index SMALLINT DEFAULT NULL, ADD subject VARCHAR(120) DEFAULT NULL');
        $this->addSql("UPDATE family_activity SET weekday = JSON_EXTRACT(class_times, '$[0].weekday'), slot_index = JSON_EXTRACT(class_times, '$[0].slot'), subject = JSON_UNQUOTE(JSON_EXTRACT(class_times, '$[0].subject')) WHERE kind = 'weekly'");
        $this->addSql('ALTER TABLE family_activity DROP class_times');
    }
}
