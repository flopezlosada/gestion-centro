<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * schedule_entry.source: quién escribió la celda del horario, la importación de Peñalara o una persona
 * desde el editor manual de guardias.
 *
 * Hasta ahora una reimportación borraba TODAS las celdas del profesor en el curso, así que se llevaba
 * por delante las guardias marcadas a mano (el editor manual existe justo para cuando el export trae
 * las clases pero no las guardias). Con esta columna la importación solo reemplaza lo suyo.
 *
 * Las filas existentes se marcan como 'penalara': en la base de datos no hay forma de distinguirlas
 * (una guardia importada también viene sin grupo, aula ni materia), y salvo un puñado todas vienen del
 * export. Si en este curso hay guardias marcadas a mano, hay que volver a marcarlas después de migrar.
 */
final class Version20260725190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'schedule_entry.source: distingue las celdas importadas de las marcadas a mano.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE schedule_entry ADD source VARCHAR(16) DEFAULT 'penalara' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule_entry DROP source');
    }
}
