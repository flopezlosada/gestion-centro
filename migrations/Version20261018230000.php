<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Días y horas en que cada familia puede venir (espacio de familias)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE family_availability (id INT AUTO_INCREMENT NOT NULL, weekday SMALLINT NOT NULL, available_from TIME NOT NULL, available_until TIME NOT NULL, family_id INT NOT NULL, INDEX IDX_88CA7722C35E566A (family_id), UNIQUE INDEX uniq_family_availability_day (family_id, weekday), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE family_availability ADD CONSTRAINT FK_88CA7722C35E566A FOREIGN KEY (family_id) REFERENCES family_volunteer (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE family_availability');
    }
}
