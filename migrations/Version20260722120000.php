<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Guardias: se elimina el paso "Confirmar". Una guardia asignada cuenta como hecha por defecto; el
 * único gesto humano es marcar una incidencia a posteriori. Se sustituye la columna booleana
 * {@code confirmed} (contaba solo lo confirmado) por {@code not_covered} (por defecto false: cuenta
 * todo lo asignado, salvo lo marcado como incidencia).
 *
 * Migración de datos: el flag se recrea vacío (sin incidencias), que es el estado por defecto de todo
 * cover asignado bajo la nueva regla; no había datos de incidencias que preservar.
 */
final class Version20260722120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Guardias: sustituye guardia_cover.confirmed por not_covered (asignada = hecha por defecto).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE guardia_cover ADD not_covered TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE guardia_cover DROP confirmed');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE guardia_cover ADD confirmed TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE guardia_cover DROP not_covered');
    }
}
