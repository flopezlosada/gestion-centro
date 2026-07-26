<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Apertura por fases del acceso: un interruptor general de login más una lista de personas que se
 * lo saltan.
 *
 * - app_setting: ajustes que un administrador cambia desde la aplicación (hoy solo el interruptor
 *   del login). No se inserta ninguna fila: mientras no exista, vale el valor por defecto del
 *   código, que es "acceso abierto". Así, desplegar esto no deja a nadie fuera.
 * - app_user.early_access: quién entra mientras el acceso está cerrado. Todos a false porque el
 *   acceso arranca abierto, y quien administra entra igualmente sin necesidad de la marca.
 * - app_user.access_changed_at: cuándo se le tocó el acceso por última vez, para poder echar de la
 *   aplicación a quien se le revoque estando dentro. Nulo en las filas existentes: nadie ha sido
 *   revocado todavía, y una sesión abierta solo se corta si el cambio es POSTERIOR a su inicio.
 */
final class Version20260726120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Acceso por fases: ajustes de aplicación, acceso anticipado por usuario y sello de revocación.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_setting (name VARCHAR(64) NOT NULL, setting_value VARCHAR(255) NOT NULL, PRIMARY KEY (name)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE app_user ADD early_access TINYINT(1) DEFAULT 0 NOT NULL, ADD access_changed_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_setting');
        $this->addSql('ALTER TABLE app_user DROP early_access, DROP access_changed_at');
    }
}
