<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `app_user.notification_channels_set_at`: cuándo la persona contestó a "¿por dónde quieres los avisos?".
 *
 * Hacía falta porque el aviso de Inicio se decidía mirando si el mapa de canales estaba vacío, y dejar
 * las cinco secciones en "como lo tenga la aplicación" —que es una respuesta legítima, y la que la mayoría
 * va a dar— no guarda ningún canal: se pulsaba Guardar y la pregunta seguía ahí, para siempre. Contestar
 * y elegir son cosas distintas, así que se guardan aparte.
 *
 * Backfill: a quien YA tenga algún canal elegido se le marca como contestado, para no volver a
 * preguntarle algo que ya respondió.
 */
final class Version20260731220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'app_user.notification_channels_set_at: marca de haber contestado a la pregunta de los avisos.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD notification_channels_set_at DATETIME DEFAULT NULL');
        // Quien ya eligió algo, ya contestó: el JSON deja de ser la señal, pero su contenido sigue valiendo
        // como prueba de que la pregunta se hizo.
        $this->addSql("UPDATE app_user SET notification_channels_set_at = NOW() WHERE notification_channels IS NOT NULL AND notification_channels NOT IN ('{}', '[]', '')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP notification_channels_set_at');
    }
}
