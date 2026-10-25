<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025175919 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reservas fijas: la serie semanal, el enlace de cada reserva a su serie y el permiso de Dirección.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE recurring_booking (id INT AUTO_INCREMENT NOT NULL, starts_on DATE NOT NULL, ends_on DATE NOT NULL, pattern JSON NOT NULL, purpose VARCHAR(200) NOT NULL, created_at DATETIME NOT NULL, room_id INT DEFAULT NULL, material_id INT DEFAULT NULL, holder_user_id INT DEFAULT NULL, holder_department_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, INDEX IDX_38FA785754177093 (room_id), INDEX IDX_38FA7857E308AC6F (material_id), INDEX IDX_38FA7857D576EA66 (holder_user_id), INDEX IDX_38FA7857536F87D1 (holder_department_id), INDEX IDX_38FA7857B03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE recurring_booking ADD CONSTRAINT FK_38FA785754177093 FOREIGN KEY (room_id) REFERENCES room (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE recurring_booking ADD CONSTRAINT FK_38FA7857E308AC6F FOREIGN KEY (material_id) REFERENCES material (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE recurring_booking ADD CONSTRAINT FK_38FA7857D576EA66 FOREIGN KEY (holder_user_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE recurring_booking ADD CONSTRAINT FK_38FA7857536F87D1 FOREIGN KEY (holder_department_id) REFERENCES org_unit (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE recurring_booking ADD CONSTRAINT FK_38FA7857B03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE booking ADD series_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE booking ADD CONSTRAINT FK_E00CEDDE5278319C FOREIGN KEY (series_id) REFERENCES recurring_booking (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_E00CEDDE5278319C ON booking (series_id)');

        // Dirección reparte los carros y las aulas del curso, así que lo trae de serie (como en
        // RoleFixtures). JSON_MERGE_PATCH con guarda y no JSON_SET, que falla en silencio sobre el array
        // vacío que llevan muchos roles; si el centro ya le tecleó un nivel, se respeta.
        $this->addSql(
            "UPDATE role
                SET permissions = JSON_MERGE_PATCH(permissions, JSON_OBJECT('reservas_fijas', 'write'))
                WHERE code = 'direction'
                  AND JSON_EXISTS(permissions, '$.reservas_fijas') = 0",
        );
    }

    public function down(Schema $schema): void
    {
        // Se lleva también un `reservas_fijas` puesto a mano en /admin/roles: la pantalla que lo usa
        // tampoco existe ya en esa versión.
        $this->addSql(
            "UPDATE role
                SET permissions = JSON_REMOVE(permissions, '$.reservas_fijas')
                WHERE JSON_EXISTS(permissions, '$.reservas_fijas') = 1",
        );
        $this->addSql('ALTER TABLE booking DROP FOREIGN KEY FK_E00CEDDE5278319C');
        $this->addSql('DROP INDEX IDX_E00CEDDE5278319C ON booking');
        $this->addSql('ALTER TABLE booking DROP series_id');
        $this->addSql('ALTER TABLE recurring_booking DROP FOREIGN KEY FK_38FA785754177093');
        $this->addSql('ALTER TABLE recurring_booking DROP FOREIGN KEY FK_38FA7857E308AC6F');
        $this->addSql('ALTER TABLE recurring_booking DROP FOREIGN KEY FK_38FA7857D576EA66');
        $this->addSql('ALTER TABLE recurring_booking DROP FOREIGN KEY FK_38FA7857536F87D1');
        $this->addSql('ALTER TABLE recurring_booking DROP FOREIGN KEY FK_38FA7857B03A8386');
        $this->addSql('DROP TABLE recurring_booking');
    }
}
