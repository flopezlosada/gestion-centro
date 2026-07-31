<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `guardia_absence.slot_indexes`: en qué TRAMOS falta el profesor.
 *
 * Hasta ahora «estar ausente» no se guardaba, se deducía de las líneas del parte
 * (`GuardiaCoverRepository::absentTeacherIdsAt`), y esas líneas solo existen para los tramos en los que
 * el profesor daba CLASE. Un profesor que faltaba en una hora en la que tenía GUARDIA no dejaba ninguna
 * huella: no constaba como ausente en ese tramo, seguía asignado a un grupo al que no iba a ir y el
 * reparto automático podía incluso darle otro. Guardar los tramos convierte «¿quién falta a esta hora?»
 * en un dato que se lee en vez de una forma que se adivina.
 *
 * **Backfill**: se reconstruye desde los `slot_index` de los covers de cada ausencia, que es exactamente
 * la información que había. Queda por debajo de la realidad en el caso del bug —las horas de guardia de
 * una ausencia antigua no dejaron cover y no se pueden recuperar—, pero no inventa nada: reproduce el
 * comportamiento anterior sin empeorarlo, y a partir de aquí las ausencias nuevas se guardan completas.
 *
 * Una ausencia sin ningún cover (posible desde ahora: faltar solo a horas de guardia) se queda con la
 * lista vacía tras el backfill, que es lo correcto para los datos históricos.
 *
 * JSON y no una tabla hija: son un puñado de enteros de 0 a 7 por fila, las ausencias de un día son unas
 * pocas y el filtro por tramo se hace en PHP (`Absence::coversSlot()`), así que una tabla, su entidad y
 * su cascada no comprarían nada.
 */
final class Version20260731120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'guardia_absence.slot_indexes: los tramos que abarca la ausencia, con backfill desde los partes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE guardia_absence ADD slot_indexes JSON NOT NULL');

        // Backfill: los tramos que ya tenía cada ausencia, tal cual salen de sus covers. JSON_ARRAYAGG
        // existe en MySQL 5.7.22+ y en MariaDB 10.5+; el server del centro es MariaDB 10.11.
        $this->addSql(<<<'SQL'
            UPDATE guardia_absence a
            SET a.slot_indexes = COALESCE((
                SELECT JSON_ARRAYAGG(s.slot_index)
                FROM (SELECT DISTINCT c.absence_id, c.slot_index FROM guardia_cover c ORDER BY c.slot_index) s
                WHERE s.absence_id = a.id
            ), JSON_ARRAY())
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE guardia_absence DROP slot_indexes');
    }
}
