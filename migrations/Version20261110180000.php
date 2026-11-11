<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Las horas de una ausencia pueden ir «sin cobertura»: el docente consta como ausente, pero esas clases no
 * generan huecos en el parte ni suman carga a nadie. Por horas y no por día, porque la fila es por día y una
 * huelga parcial no puede tapar una falta real por la tarde. Las ausencias que ya existen no tienen ninguna.
 */
final class Version20261110180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Horas de ausencia sin cobertura (guardia_absence.uncovered_slot_indexes).';
    }

    public function up(Schema $schema): void
    {
        // Las filas que ya existen no tienen horas sin cobertura: se rellenan con la lista vacía.
        $this->addSql('ALTER TABLE guardia_absence ADD uncovered_slot_indexes JSON DEFAULT NULL');
        $this->addSql("UPDATE guardia_absence SET uncovered_slot_indexes = '[]'");
        $this->addSql('ALTER TABLE guardia_absence MODIFY uncovered_slot_indexes JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE guardia_absence DROP uncovered_slot_indexes');
    }
}
