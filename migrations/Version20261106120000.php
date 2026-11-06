<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `personal_event`: de UN aviso a VARIOS. La antelación pasa de una columna entera
 * (`reminder_minutes`) a una lista JSON (`reminder_offsets`); `remind_at` pasa a ser el SIGUIENTE
 * aviso pendiente y `reminder_sent_at` la marca del último enviado.
 *
 * Datos: cada aviso existente se convierte en una lista de uno. Uno ya enviado se queda sin siguiente
 * (`remind_at` a NULL), que es lo que calcula ahora la entidad; antes lo excluía la condición
 * `reminder_sent_at IS NULL` del barrido, que desaparece.
 */
final class Version20261106120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agenda personal: varios avisos por evento (reminder_minutes → reminder_offsets JSON).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE personal_event ADD reminder_offsets JSON DEFAULT NULL');
        $this->addSql("UPDATE personal_event SET reminder_offsets = IF(reminder_minutes IS NULL, '[]', CONCAT('[', reminder_minutes, ']'))");
        $this->addSql('UPDATE personal_event SET remind_at = NULL WHERE reminder_sent_at IS NOT NULL AND remind_at <= reminder_sent_at');
        $this->addSql('ALTER TABLE personal_event MODIFY reminder_offsets JSON NOT NULL, DROP reminder_minutes');
    }

    public function down(Schema $schema): void
    {
        // Vuelta atrás con pérdida: de varios avisos se queda el de MENOS antelación (el último de la
        // lista, que se guarda de mayor a menor), que es el que más se parece a lo que había. Un evento
        // con un aviso ya enviado conserva reminder_sent_at, así que el barrido antiguo ya no lo manda.
        $this->addSql('ALTER TABLE personal_event ADD reminder_minutes INT DEFAULT NULL');
        $this->addSql("UPDATE personal_event SET reminder_minutes = JSON_EXTRACT(reminder_offsets, CONCAT('$[', JSON_LENGTH(reminder_offsets) - 1, ']')) WHERE JSON_LENGTH(reminder_offsets) > 0");
        $this->addSql('UPDATE personal_event SET remind_at = DATE_SUB(start_at, INTERVAL reminder_minutes MINUTE) WHERE reminder_minutes IS NOT NULL');
        $this->addSql('ALTER TABLE personal_event DROP reminder_offsets');
    }
}
