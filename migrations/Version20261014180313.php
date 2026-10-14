<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014180313 extends AbstractMigration
{
    /** Same value as App\Contract\AuditRedacted::MARK, copied: a migration must not change with the code. */
    private const string MARK = '(contenido no guardado)';

    public function getDescription(): string
    {
        return 'Marca de documento borrado al pasar el día en guardias y encargos de fotocopias, y fuera de la auditoría el nombre del documento de tarea';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE copy_request ADD document_purged_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE guardia_cover ADD task_document_purged_at DATETIME DEFAULT NULL');

        // El nombre original del fichero puede nombrar a un alumno: el ya guardado en la auditoría pasa a la
        // marca, igual que hará desde ahora el atributo AuditRedacted. La ruta no: es un UUID.
        foreach (['old', 'new'] as $side) {
            $this->addSql(\sprintf(
                "UPDATE audit_log SET changes = JSON_SET(changes, '%1\$s', CASE WHEN JSON_TYPE(JSON_EXTRACT(changes, '%1\$s')) = 'NULL' OR JSON_UNQUOTE(JSON_EXTRACT(changes, '%1\$s')) = '' THEN NULL ELSE '%2\$s' END) WHERE subject_type = 'GuardiaCover' AND changes IS NOT NULL AND JSON_CONTAINS_PATH(changes, 'one', '%1\$s')",
                \sprintf('$.taskDocumentName.%s', $side),
                self::MARK,
            ));
        }
    }

    /**
     * Only the columns come back: what the audit log had is gone for good, on purpose.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE copy_request DROP document_purged_at');
        $this->addSql('ALTER TABLE guardia_cover DROP task_document_purged_at');
    }
}
