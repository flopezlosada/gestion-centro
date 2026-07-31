<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cómo quiere cada persona que le lleguen los avisos, sección por sección.
 *
 * El centro contó que al activar los avisos del móvil los correos seguían llegando igual, y pidió poder
 * elegir: móvil, correo o los dos, con ajustes distintos por sección (tareas, guardias, reuniones…).
 *
 * Un JSON en la propia persona y no una tabla aparte: son cinco escalares que solo se leen junto a ella
 * y que nadie consulta cruzados ("¿quién quiere correo?" no es una pregunta que se haga). Vacío = no lo
 * ha elegido, que NO es lo mismo que ninguno de los tres canales: mientras esté vacío manda la política
 * por defecto de la aplicación, así que esta migración no cambia el comportamiento de nadie.
 */
final class Version20260731100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'app_user: canal de avisos elegido por sección.';
    }

    public function up(Schema $schema): void
    {
        // En tres pasos y no en uno: la tabla ya tiene el claustro dentro, y una columna JSON NOT NULL
        // añadida de golpe deja esas filas con un valor que no es JSON válido.
        $this->addSql('ALTER TABLE app_user ADD notification_channels JSON DEFAULT NULL');
        $this->addSql("UPDATE app_user SET notification_channels = '{}'");
        $this->addSql('ALTER TABLE app_user MODIFY notification_channels JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP notification_channels');
    }
}
