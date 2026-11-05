<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reuniones: huella de la plantilla del acta en la nube, para rehacerla mientras nadie la ha tocado.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE meeting ADD minutes_cloud_template_hash VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE meeting DROP minutes_cloud_template_hash');
    }
}
