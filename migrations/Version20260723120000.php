<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Web Push: nueva tabla push_subscription, una fila por navegador suscrito de cada usuario (endpoint
 * del servicio de push + claves p256dh/auth para cifrar el payload). El endpoint es único (un
 * navegador que se re-suscribe manda el mismo, así que se actualiza en vez de duplicar). Se borra en
 * cascada con el usuario; el envío purga por su cuenta las suscripciones caducadas (404/410).
 */
final class Version20260723120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Web Push: crea push_subscription (una por navegador suscrito, endpoint único).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE push_subscription (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, endpoint VARCHAR(512) NOT NULL, p256dh VARCHAR(255) NOT NULL, auth VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_push_subscription_endpoint (endpoint), INDEX idx_push_subscription_user (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE push_subscription ADD CONSTRAINT FK_push_subscription_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE push_subscription DROP FOREIGN KEY FK_push_subscription_user');
        $this->addSql('DROP TABLE push_subscription');
    }
}
