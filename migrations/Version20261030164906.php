<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030164906 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Material: cuántos equipos lleva cada carro.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE material ADD device_count SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE material DROP device_count');
    }
}
