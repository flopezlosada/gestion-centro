<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Apoyo puntual de guardias: compañeros que el equipo directivo da de alta A MANO para un día y una
 * hora concretos, porque están libres aunque su horario diga que dan clase (2º de Bachillerato o CFGB
 * han terminado ya las clases).
 *
 * guardia_support va por FECHA, no por día de la semana: el horario semanal ya vive en schedule_entry
 * y mezclar ahí un arreglo de un martes concreto confundiría dos cosas distintas. Por eso, además,
 * ninguna reimportación de Peñalara puede tocar estas filas.
 *
 * Sin datos que rellenar: hasta ahora no existía ninguna forma de expresar esto, así que la tabla
 * arranca vacía y el reparto se comporta igual que antes mientras nadie dé de alta a nadie.
 */
final class Version20260730120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Apoyo puntual de guardias: alta manual de profesorado disponible en un día y tramo concretos.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE guardia_support (id INT AUTO_INCREMENT NOT NULL, teacher_id INT NOT NULL, support_date DATE NOT NULL, slot_index SMALLINT NOT NULL, note VARCHAR(255) DEFAULT NULL, UNIQUE INDEX UNIQ_guardia_support (teacher_id, support_date, slot_index), INDEX IDX_support_date_slot (support_date, slot_index), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE guardia_support ADD CONSTRAINT FK_guardia_support_teacher FOREIGN KEY (teacher_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE guardia_support DROP FOREIGN KEY FK_guardia_support_teacher');
        $this->addSql('DROP TABLE guardia_support');
    }
}
