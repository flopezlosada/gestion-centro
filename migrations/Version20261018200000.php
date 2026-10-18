<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cuentas de las familias que colaboran en las actividades (espacio de familias)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE family_volunteer (id INT AUTO_INCREMENT NOT NULL, full_name VARCHAR(180) NOT NULL, email VARCHAR(180) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_988E44B6E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE family_volunteer');
    }
}
