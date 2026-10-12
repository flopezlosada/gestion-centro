<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The activity trail stops keeping the TEXT of a meeting's acta and agenda: the audit now records that
 * those fields changed, and whether they were set or cleared, with a fixed mark instead of each value
 * (App\Contract\AuditRedacted). This purges the text already stored — word for word, before and after
 * every edit — so it lives in one place only and not again in every audit row and every backup.
 *
 * Each value is replaced by the same mark the code now writes, so old and new entries read alike and the
 * meeting history keeps its events ("Modificó el desarrollo del acta").
 *
 * Irreversible on purpose: getting the text back is exactly what this migration exists to make impossible.
 */
final class Version20261012180000 extends AbstractMigration
{
    /** Same value as App\Contract\AuditRedacted::MARK, copied: a migration must not change with the code. */
    private const string MARK = '(contenido no guardado)';

    public function getDescription(): string
    {
        return 'Purge the text of meeting actas and agendas kept in audit_log.';
    }

    public function up(Schema $schema): void
    {
        foreach (['agenda', 'discussion', 'agreements'] as $field) {
            foreach (['old', 'new'] as $side) {
                $path = \sprintf('$.%s.%s', $field, $side);
                // Vacío o null se queda null (que se borró sigue constando); cualquier texto pasa a la marca.
                $this->addSql(\sprintf(
                    "UPDATE audit_log SET changes = JSON_SET(changes, '%1\$s', CASE WHEN JSON_TYPE(JSON_EXTRACT(changes, '%1\$s')) = 'NULL' OR JSON_UNQUOTE(JSON_EXTRACT(changes, '%1\$s')) = '' THEN NULL ELSE '%2\$s' END) WHERE subject_type = 'Meeting' AND changes IS NOT NULL AND JSON_CONTAINS_PATH(changes, 'one', '%1\$s')",
                    $path,
                    self::MARK,
                ));
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('El texto purgado no se puede recuperar, a propósito.');
    }
}
