<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A booking can be for several groups at once, picked from the timetable's list: `booking.group_name`
 * grows from 40 to 255 characters to hold the ", "-joined snapshot ("E1A, E1B, E1C").
 *
 * Widening only, so every value already stored fits unchanged. The way back truncates anything longer
 * than 40 characters — a multi-group snapshot loses its tail rather than blocking the rollback.
 */
final class Version20260924120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A booking can name several groups: booking.group_name widens to 255 characters.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booking CHANGE group_name group_name VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE booking SET group_name = LEFT(group_name, 40) WHERE CHAR_LENGTH(group_name) > 40');
        $this->addSql('ALTER TABLE booking CHANGE group_name group_name VARCHAR(40) DEFAULT NULL');
    }
}
