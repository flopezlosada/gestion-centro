<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `guardia_cover.incident_note`: lo que quien cubre una guardia cuenta que ha pasado.
 *
 * Hasta ahora la única marca era `not_covered`, y solo la podía poner la coordinación desde el parte:
 * quien estaba cubriendo tenía que localizarla por teléfono o en persona para que constara. Una sola
 * columna basta — que esté rellena ES la incidencia — y el quién y el cuándo ya los guarda el histórico,
 * porque {@see \App\Entity\GuardiaCover} es `Auditable`.
 */
final class Version20260731210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'guardia_cover.incident_note: la incidencia que reporta quien cubre la guardia.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE guardia_cover ADD incident_note LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE guardia_cover DROP incident_note');
    }
}
