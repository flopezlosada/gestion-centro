<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Reuniones y proyectos (petición D del centro, 30-07-2026).
 *
 * - `project`: un proyecto del centro (Erasmus+, huerto, plan digital…) con su coordinación
 *   (`coordinator_id`) y su profesorado (`project_member`). NO es un departamento: la pertenencia es
 *   muchos-a-muchos y la coordinación no da rango jerárquico.
 * - `meeting`: una reunión convocada (día y HORA, lugar, orden del día) con sus convocados
 *   (`meeting_attendee`) y su acta (`minutes_*`), que aquí sí se guarda como fichero.
 * - Rol `project_coordinator` ("Coordinación de proyectos"): sin rango y sin permisos de área — el
 *   alcance vive en `project.coordinator_id`. Se inserta aquí (y no solo en las fixtures) porque en
 *   producción el catálogo de roles ya existe y nadie vuelve a cargar fixtures; `INSERT ... SELECT`
 *   con `NOT EXISTS` lo hace idempotente y no pisa el rol si ya se creó a mano.
 *
 * Las FK a `app_user` son ON DELETE SET NULL en quien convoca y quien sube el acta (borrar a una
 * persona no puede llevarse por delante el registro de lo acordado) y ON DELETE CASCADE en las tablas
 * de pertenencia (una lista de convocados no puede apuntar a nadie).
 */
final class Version20260730170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reuniones con acta y proyectos con coordinación (tablas project, project_member, meeting, meeting_attendee + rol de coordinación de proyectos).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE project (id INT AUTO_INCREMENT NOT NULL, coordinator_id INT DEFAULT NULL, name VARCHAR(120) NOT NULL, description LONGTEXT DEFAULT NULL, active TINYINT(1) NOT NULL, UNIQUE INDEX uniq_project_name (name), INDEX IDX_project_coordinator (coordinator_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE project ADD CONSTRAINT FK_project_coordinator FOREIGN KEY (coordinator_id) REFERENCES app_user (id) ON DELETE SET NULL');

        $this->addSql('CREATE TABLE project_member (project_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_project_member_project (project_id), INDEX IDX_project_member_user (user_id), PRIMARY KEY (project_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE project_member ADD CONSTRAINT FK_project_member_project FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_member ADD CONSTRAINT FK_project_member_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE meeting (id INT AUTO_INCREMENT NOT NULL, convener_id INT DEFAULT NULL, project_id INT DEFAULT NULL, minutes_uploaded_by_id INT DEFAULT NULL, title VARCHAR(200) NOT NULL, agenda LONGTEXT DEFAULT NULL, place VARCHAR(120) DEFAULT NULL, start_at DATETIME NOT NULL, end_at DATETIME DEFAULT NULL, minutes_path VARCHAR(255) DEFAULT NULL, minutes_name VARCHAR(255) DEFAULT NULL, minutes_uploaded_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, INDEX idx_meeting_start (start_at), INDEX IDX_meeting_convener (convener_id), INDEX IDX_meeting_project (project_id), INDEX IDX_meeting_minutes_by (minutes_uploaded_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE meeting ADD CONSTRAINT FK_meeting_convener FOREIGN KEY (convener_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE meeting ADD CONSTRAINT FK_meeting_project FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE meeting ADD CONSTRAINT FK_meeting_minutes_by FOREIGN KEY (minutes_uploaded_by_id) REFERENCES app_user (id) ON DELETE SET NULL');

        $this->addSql('CREATE TABLE meeting_attendee (meeting_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_meeting_attendee_meeting (meeting_id), INDEX IDX_meeting_attendee_user (user_id), PRIMARY KEY (meeting_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE meeting_attendee ADD CONSTRAINT FK_meeting_attendee_meeting FOREIGN KEY (meeting_id) REFERENCES meeting (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE meeting_attendee ADD CONSTRAINT FK_meeting_attendee_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');

        // El rol nuevo del catálogo. Sin rango (hierarchy_level NULL), sin área (permissions {}) y de
        // centro (per_department 0). Idempotente: no hace nada si el código ya existe.
        $this->addSql("INSERT INTO role (code, name, description, permissions, admin, per_department, hierarchy_level) SELECT 'project_coordinator', 'Coordinación de proyectos', NULL, '{}', 0, 0, NULL FROM (SELECT 1) AS placeholder WHERE NOT EXISTS (SELECT 1 FROM role WHERE code = 'project_coordinator')");
    }

    public function down(Schema $schema): void
    {
        // El rol se va solo si nadie lo tiene asignado: quitárselo a la gente en un rollback sería
        // perder un dato que la migración no creó.
        $this->addSql("DELETE FROM role WHERE code = 'project_coordinator' AND id NOT IN (SELECT role_id FROM user_role)");

        $this->addSql('ALTER TABLE meeting_attendee DROP FOREIGN KEY FK_meeting_attendee_meeting');
        $this->addSql('ALTER TABLE meeting_attendee DROP FOREIGN KEY FK_meeting_attendee_user');
        $this->addSql('DROP TABLE meeting_attendee');

        $this->addSql('ALTER TABLE meeting DROP FOREIGN KEY FK_meeting_convener');
        $this->addSql('ALTER TABLE meeting DROP FOREIGN KEY FK_meeting_project');
        $this->addSql('ALTER TABLE meeting DROP FOREIGN KEY FK_meeting_minutes_by');
        $this->addSql('DROP TABLE meeting');

        $this->addSql('ALTER TABLE project_member DROP FOREIGN KEY FK_project_member_project');
        $this->addSql('ALTER TABLE project_member DROP FOREIGN KEY FK_project_member_user');
        $this->addSql('DROP TABLE project_member');

        $this->addSql('ALTER TABLE project DROP FOREIGN KEY FK_project_coordinator');
        $this->addSql('DROP TABLE project');
    }
}
