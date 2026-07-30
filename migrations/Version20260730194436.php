<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recordatorio de RAICES por guardia: marca de cuándo se envió el aviso "entra en RAICES y apunta las
 * ausencias del alumnado de esta sesión" a quien la está cubriendo.
 *
 * - raices_reminder_sent_at: cuándo salió el aviso, para que el barrido (cada pocos minutos, ver
 *   App\Service\GuardiaRaicesReminder) sea idempotente y no repita el push durante toda la hora.
 *
 * Las filas existentes quedan a null: las guardias ya pasadas no reciben aviso retroactivo, porque el
 * barrido solo mira las horas que están ocurriendo AHORA. No hay índice propio: el barrido ya entra por
 * (cover_date) — que IDX_cover_date_slot cubre por prefijo — y filtra el resto en una decena de filas.
 *
 * NOMBRE CON HORA REAL (19:44:36), no redonda: esta migración chocó DOS veces con otra rama del mismo
 * día por elegir las dos un "…150000". Con varias ramas abiertas a la vez, los números redondos se
 * agotan y el choque no es un conflicto de texto sino dos clases con el mismo nombre — que revienta el
 * despliegue, no el merge. La convención de Doctrine es un instante: usarlo de verdad lo hace imposible.
 */
final class Version20260730194436 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'guardia_cover: marca de envío del recordatorio de RAICES.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE guardia_cover ADD raices_reminder_sent_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE guardia_cover DROP raices_reminder_sent_at');
    }
}
