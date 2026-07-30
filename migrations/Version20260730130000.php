<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Agrupación de guardias: varios grupos de la misma hora reunidos en un aula para que un solo profesor
 * pueda atenderlos, con el aula a la que se manda la clase que había allí (biblioteca, salón de actos).
 *
 * Es aditivo: guardia_cover.grouping_id es NULLABLE y ON DELETE SET NULL, así que deshacer una
 * agrupación devuelve cada clase a su aula sin perder nada. Las líneas del parte conservan su profesor
 * ausente, su grupo, su aula original y su tarea; la agrupación solo dice dónde se juntan.
 *
 * A quién se desplazó NO se guarda: se deduce del horario. Lo que no se puede deducir —a dónde se le
 * manda— sí (displaced_to_room). UNIQUE (fecha, tramo, aula) porque dos agrupaciones en la misma aula a
 * la misma hora no significan nada.
 *
 * Sin datos que rellenar: hasta ahora no existía la agrupación, así que la tabla arranca vacía y todo
 * cover existente se queda con grouping_id NULL, que es su comportamiento de siempre.
 */
final class Version20260730130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agrupación de guardias: varios grupos en un aula y aula de destino de la clase desplazada.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE guardia_grouping (id INT AUTO_INCREMENT NOT NULL, grouping_date DATE NOT NULL, slot_index SMALLINT NOT NULL, room_name VARCHAR(64) NOT NULL, displaced_to_room VARCHAR(64) DEFAULT NULL, note VARCHAR(255) DEFAULT NULL, UNIQUE INDEX UNIQ_guardia_grouping (grouping_date, slot_index, room_name), INDEX IDX_grouping_date_slot (grouping_date, slot_index), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE guardia_cover ADD grouping_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE guardia_cover ADD CONSTRAINT FK_guardia_cover_grouping FOREIGN KEY (grouping_id) REFERENCES guardia_grouping (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_cover_grouping ON guardia_cover (grouping_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE guardia_cover DROP FOREIGN KEY FK_guardia_cover_grouping');
        $this->addSql('DROP INDEX IDX_cover_grouping ON guardia_cover');
        $this->addSql('ALTER TABLE guardia_cover DROP grouping_id');
        $this->addSql('DROP TABLE guardia_grouping');
    }
}
