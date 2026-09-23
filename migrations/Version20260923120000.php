<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Grupos estándar de convocatoria (petición del centro tras el go-live, 23-09-2026): una lista guardada
 * de gente que se convoca junta a menudo — "Tutores/as 2º ESO", "CCP" — para no marcarlos uno a uno cada
 * vez que se convoca esa reunión.
 *
 * `meeting_group` + `meeting_group_member` es el mismo par que `project` + `project_member`, pero sin
 * ningún vínculo hacia `meeting`: un grupo solo se usa como atajo que precarga `attendees` al convocar
 * ({@see \App\Controller\MeetingController::new()}), nunca se guarda como referencia de la reunión. Por
 * eso `meeting_group` no necesita ON DELETE en ningún sitio salvo su propia tabla de pertenencia: borrar
 * un grupo no afecta a ninguna reunión ya convocada.
 */
final class Version20260923120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Grupos estándar de convocatoria de reuniones (tablas meeting_group, meeting_group_member).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE meeting_group (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, active TINYINT(1) DEFAULT 1 NOT NULL, UNIQUE INDEX uniq_meeting_group_name (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('CREATE TABLE meeting_group_member (meeting_group_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_meeting_group_member_group (meeting_group_id), INDEX IDX_meeting_group_member_user (user_id), PRIMARY KEY (meeting_group_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE meeting_group_member ADD CONSTRAINT FK_meeting_group_member_group FOREIGN KEY (meeting_group_id) REFERENCES meeting_group (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE meeting_group_member ADD CONSTRAINT FK_meeting_group_member_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE meeting_group_member DROP FOREIGN KEY FK_meeting_group_member_group');
        $this->addSql('ALTER TABLE meeting_group_member DROP FOREIGN KEY FK_meeting_group_member_user');
        $this->addSql('DROP TABLE meeting_group_member');
        $this->addSql('DROP TABLE meeting_group');
    }
}
