<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `guardia_cover.evening_reminder_sent_at` y `morning_reminder_sent_at`: los sellos del **doble
 * recordatorio de guardia** que pidió el centro (la tarde anterior y esa misma mañana).
 *
 * Dos columnas y no una porque el requisito del centro es **no duplicar dentro de cada disparo**: una sola
 * marca de "ya se avisó" haría que el segundo aviso no saliera nunca, y una marca con la fecha del último
 * envío obligaría a adivinar a qué disparo correspondía. Con una por disparo, la pregunta que hace el
 * barrido ("¿le he avisado ya de ESTA guardia en ESTE momento?") es una columna, no una deducción.
 *
 * Y son de la guardia, no de la persona: una falta apuntada a las nueve de la noche añade una guardia a
 * quien ya recibió su aviso de la tarde, y esa sí tiene que avisarse. Marcando por guardia, la nueva sale y
 * las viejas no se repiten.
 *
 * Sin backfill: NULL significa "todavía no ha salido", que es la verdad para todo lo que ya existe. Las
 * guardias pasadas no van a recibir nada porque el barrido solo mira el día siguiente y el de hoy.
 */
final class Version20260803100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'guardia_cover: sellos del doble recordatorio de guardia (tarde anterior y esa misma mañana).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE guardia_cover ADD evening_reminder_sent_at DATETIME DEFAULT NULL, ADD morning_reminder_sent_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE guardia_cover DROP evening_reminder_sent_at, DROP morning_reminder_sent_at');
    }
}
