<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Reuniones periódicas (petición del profesorado tras el go-live): un grupo de convocatoria puede
 * repetirse cada semana, con su día, su hora, quién convoca y de qué tipo es. Los grupos de Peñalara
 * se importan con su nombre como clave (`penalara_key`), y la tarea diaria genera cada semana su reunión,
 * enlazada al grupo (`meeting.meeting_group_id`) para saber cuál es «la reunión anterior» cuyo acta se
 * aprueba.
 *
 * Todas las claves ajenas nuevas son ON DELETE SET NULL: borrar un grupo, una persona o un tipo no se
 * lleva ninguna reunión ya celebrada. El índice único (meeting_group_id, start_at) es el respaldo de la
 * tarea diaria contra una reunión generada dos veces; no estorba a las convocadas a mano, que llevan el
 * grupo a NULL y un índice único admite varios NULL.
 */
final class Version20260923160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reuniones periódicas: repetición semanal en meeting_group y enlace meeting → meeting_group.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE meeting_group ADD weekday SMALLINT DEFAULT NULL, ADD slot_index SMALLINT DEFAULT NULL, ADD convener_id INT DEFAULT NULL, ADD meeting_type_id INT DEFAULT NULL, ADD penalara_key VARCHAR(120) DEFAULT NULL, ADD edited_since_import TINYINT(1) DEFAULT 0 NOT NULL, ADD generated_through DATE DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5F106C554A8B118 ON meeting_group (penalara_key)');
        $this->addSql('CREATE INDEX IDX_5F106C59E575D69 ON meeting_group (convener_id)');
        $this->addSql('CREATE INDEX IDX_5F106C55D9EC41E ON meeting_group (meeting_type_id)');
        $this->addSql('ALTER TABLE meeting_group ADD CONSTRAINT FK_5F106C59E575D69 FOREIGN KEY (convener_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE meeting_group ADD CONSTRAINT FK_5F106C55D9EC41E FOREIGN KEY (meeting_type_id) REFERENCES meeting_type (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE meeting ADD meeting_group_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_F515E1396DC5F492 ON meeting (meeting_group_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_meeting_group_start ON meeting (meeting_group_id, start_at)');
        $this->addSql('ALTER TABLE meeting ADD CONSTRAINT FK_F515E1396DC5F492 FOREIGN KEY (meeting_group_id) REFERENCES meeting_group (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE meeting DROP FOREIGN KEY FK_F515E1396DC5F492');
        $this->addSql('DROP INDEX uniq_meeting_group_start ON meeting');
        $this->addSql('DROP INDEX IDX_F515E1396DC5F492 ON meeting');
        $this->addSql('ALTER TABLE meeting DROP meeting_group_id');

        $this->addSql('ALTER TABLE meeting_group DROP FOREIGN KEY FK_5F106C59E575D69');
        $this->addSql('ALTER TABLE meeting_group DROP FOREIGN KEY FK_5F106C55D9EC41E');
        $this->addSql('DROP INDEX UNIQ_5F106C554A8B118 ON meeting_group');
        $this->addSql('DROP INDEX IDX_5F106C59E575D69 ON meeting_group');
        $this->addSql('DROP INDEX IDX_5F106C55D9EC41E ON meeting_group');
        $this->addSql('ALTER TABLE meeting_group DROP weekday, DROP slot_index, DROP convener_id, DROP meeting_type_id, DROP penalara_key, DROP edited_since_import, DROP generated_through');
    }
}
