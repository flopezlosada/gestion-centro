<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Familias apuntadas a las actividades (espacio de familias)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE family_signup (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, activity_id INT NOT NULL, family_id INT NOT NULL, INDEX IDX_879349FA81C06096 (activity_id), INDEX IDX_879349FAC35E566A (family_id), UNIQUE INDEX uniq_family_signup (activity_id, family_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE family_signup ADD CONSTRAINT FK_879349FA81C06096 FOREIGN KEY (activity_id) REFERENCES family_activity (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE family_signup ADD CONSTRAINT FK_879349FAC35E566A FOREIGN KEY (family_id) REFERENCES family_volunteer (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE family_signup');
    }
}
