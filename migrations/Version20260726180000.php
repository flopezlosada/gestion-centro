<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recordatorios push de la agenda personal: cada evento con hora puede pedir un aviso "N minutos
 * antes" (como Google Calendar).
 *
 * - reminder_minutes: la preferencia del dueño (minutos de antelación), null si no quiere aviso.
 * - remind_at: el instante en que toca avisar, DERIVADO de start_at menos la antelación. Se guarda
 *   materializado para que el barrido (que corre cada pocos minutos sobre toda la tabla) sea un
 *   rango indexado y no una resta de fechas sobre columna.
 * - reminder_sent_at: cuándo se envió, para que el barrido sea idempotente.
 *
 * Las filas existentes quedan a null en las tres: los eventos ya creados no avisan hasta que su dueño
 * los edite y elija antelación.
 */
final class Version20260726180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'personal_event: antelación, instante y marca de envío del recordatorio push.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE personal_event
            ADD reminder_minutes INT DEFAULT NULL,
            ADD remind_at DATETIME DEFAULT NULL,
            ADD reminder_sent_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_personal_event_remind ON personal_event (remind_at, reminder_sent_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_personal_event_remind ON personal_event');
        $this->addSql('ALTER TABLE personal_event
            DROP reminder_minutes,
            DROP remind_at,
            DROP reminder_sent_at');
    }
}
