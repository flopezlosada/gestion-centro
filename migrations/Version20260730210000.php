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
 *   (`meeting_attendee`) y su acta (`minutes_*`), que aquí sí se guarda como fichero. El recordatorio
 *   push va materializado igual que en `personal_event`: la antelación que eligió quien convoca
 *   (`reminder_minutes`), el instante DERIVADO en que toca avisar (`remind_at`, indexado junto a
 *   `reminder_sent_at` porque el barrido corre cada pocos minutos sobre toda la tabla) y la marca de
 *   enviado, una sola para toda la reunión (se avisa a todos en la misma pasada). Lleva además quién
 *   levanta el acta (`minutes_taken_by_id`, que NO siempre es quien convoca), si esa acta se aprueba en la
 *   reunión siguiente (`minutes_approval_required` + `minutes_approved_*`) y la asistencia real
 *   (`meeting_attendance` + `attendance_taken_at`, que distingue "no vino nadie" de "no se pasó lista").
 *   `discussion` guarda lo tratado y lo acordado, que es la materia prima con la que se genera el acta en
 *   PDF cuando alguien la pide.
 * - `role.can_convene`: qué cargos convocan reuniones. Regla del centro: todos menos el docente raso.
 * - Rol `project_coordinator` ("Coordinación de proyectos"): sin rango y sin permisos de área — el
 *   alcance vive en `project.coordinator_id`. Se inserta aquí (y no solo en las fixtures) porque en
 *   producción el catálogo de roles ya existe y nadie vuelve a cargar fixtures; `INSERT ... SELECT`
 *   con `NOT EXISTS` lo hace idempotente y no pisa el rol si ya se creó a mano.
 *
 * Las FK a `app_user` son ON DELETE SET NULL en quien convoca y quien sube el acta (borrar a una
 * persona no puede llevarse por delante el registro de lo acordado) y ON DELETE CASCADE en las tablas
 * de pertenencia (una lista de convocados no puede apuntar a nadie).
 */
final class Version20260730210000 extends AbstractMigration
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

        $this->addSql('CREATE TABLE meeting (id INT AUTO_INCREMENT NOT NULL, convener_id INT DEFAULT NULL, project_id INT DEFAULT NULL, minutes_uploaded_by_id INT DEFAULT NULL, title VARCHAR(200) NOT NULL, agenda LONGTEXT DEFAULT NULL, discussion LONGTEXT DEFAULT NULL, place VARCHAR(120) DEFAULT NULL, start_at DATETIME NOT NULL, end_at DATETIME DEFAULT NULL, minutes_path VARCHAR(255) DEFAULT NULL, minutes_name VARCHAR(255) DEFAULT NULL, minutes_uploaded_at DATETIME DEFAULT NULL, minutes_taken_by_id INT DEFAULT NULL, minutes_approval_required TINYINT(1) DEFAULT 0 NOT NULL, minutes_approved_at DATETIME DEFAULT NULL, minutes_approved_by_id INT DEFAULT NULL, attendance_taken_at DATETIME DEFAULT NULL, reminder_minutes INT DEFAULT NULL, remind_at DATETIME DEFAULT NULL, reminder_sent_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, INDEX idx_meeting_start (start_at), INDEX idx_meeting_remind (remind_at, reminder_sent_at), INDEX IDX_meeting_convener (convener_id), INDEX IDX_meeting_project (project_id), INDEX IDX_meeting_minutes_by (minutes_uploaded_by_id), INDEX IDX_meeting_minutes_taken_by (minutes_taken_by_id), INDEX IDX_meeting_minutes_approved_by (minutes_approved_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE meeting ADD CONSTRAINT FK_meeting_convener FOREIGN KEY (convener_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE meeting ADD CONSTRAINT FK_meeting_project FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE meeting ADD CONSTRAINT FK_meeting_minutes_by FOREIGN KEY (minutes_uploaded_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE meeting ADD CONSTRAINT FK_meeting_minutes_taken_by FOREIGN KEY (minutes_taken_by_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE meeting ADD CONSTRAINT FK_meeting_minutes_approved_by FOREIGN KEY (minutes_approved_by_id) REFERENCES app_user (id) ON DELETE SET NULL');

        $this->addSql('CREATE TABLE meeting_attendee (meeting_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_meeting_attendee_meeting (meeting_id), INDEX IDX_meeting_attendee_user (user_id), PRIMARY KEY (meeting_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE meeting_attendee ADD CONSTRAINT FK_meeting_attendee_meeting FOREIGN KEY (meeting_id) REFERENCES meeting (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE meeting_attendee ADD CONSTRAINT FK_meeting_attendee_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE meeting_attendance (meeting_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_meeting_attendance_meeting (meeting_id), INDEX IDX_meeting_attendance_user (user_id), PRIMARY KEY (meeting_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE meeting_attendance ADD CONSTRAINT FK_meeting_attendance_meeting FOREIGN KEY (meeting_id) REFERENCES meeting (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE meeting_attendance ADD CONSTRAINT FK_meeting_attendance_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');

        // Quién convoca reuniones, como bandera del rol: "todos los cargos convocan" (regla del centro),
        // o sea todo el catálogo MENOS el rol de docente raso, que es a quien se convoca. Se aplica a las
        // filas que ya existen para que el catálogo real del centro quede bien sin tocarlo a mano.
        $this->addSql("ALTER TABLE role ADD can_convene TINYINT(1) DEFAULT 0 NOT NULL");
        $this->addSql("UPDATE role SET can_convene = 1 WHERE code <> 'teacher'");

        // El rol nuevo del catálogo. Sin rango (hierarchy_level NULL), sin área (permissions {}) y de
        // centro (per_department 0). Idempotente: no hace nada si el código ya existe.
        $this->addSql("INSERT INTO role (code, name, description, permissions, admin, per_department, hierarchy_level, can_convene) SELECT 'project_coordinator', 'Coordinación de proyectos', NULL, '{}', 0, 0, NULL, 1 FROM (SELECT 1) AS placeholder WHERE NOT EXISTS (SELECT 1 FROM role WHERE code = 'project_coordinator')");
    }

    public function down(Schema $schema): void
    {
        // El rol se va solo si nadie lo tiene asignado: quitárselo a la gente en un rollback sería
        // perder un dato que la migración no creó.
        $this->addSql("DELETE FROM role WHERE code = 'project_coordinator' AND id NOT IN (SELECT role_id FROM user_role)");

        $this->addSql('ALTER TABLE role DROP can_convene');

        $this->addSql('ALTER TABLE meeting_attendance DROP FOREIGN KEY FK_meeting_attendance_meeting');
        $this->addSql('ALTER TABLE meeting_attendance DROP FOREIGN KEY FK_meeting_attendance_user');
        $this->addSql('DROP TABLE meeting_attendance');

        $this->addSql('ALTER TABLE meeting_attendee DROP FOREIGN KEY FK_meeting_attendee_meeting');
        $this->addSql('ALTER TABLE meeting_attendee DROP FOREIGN KEY FK_meeting_attendee_user');
        $this->addSql('DROP TABLE meeting_attendee');

        $this->addSql('ALTER TABLE meeting DROP FOREIGN KEY FK_meeting_convener');
        $this->addSql('ALTER TABLE meeting DROP FOREIGN KEY FK_meeting_project');
        $this->addSql('ALTER TABLE meeting DROP FOREIGN KEY FK_meeting_minutes_by');
        $this->addSql('ALTER TABLE meeting DROP FOREIGN KEY FK_meeting_minutes_taken_by');
        $this->addSql('ALTER TABLE meeting DROP FOREIGN KEY FK_meeting_minutes_approved_by');
        $this->addSql('DROP TABLE meeting');

        $this->addSql('ALTER TABLE project_member DROP FOREIGN KEY FK_project_member_project');
        $this->addSql('ALTER TABLE project_member DROP FOREIGN KEY FK_project_member_user');
        $this->addSql('DROP TABLE project_member');

        $this->addSql('ALTER TABLE project DROP FOREIGN KEY FK_project_coordinator');
        $this->addSql('DROP TABLE project');
    }
}
