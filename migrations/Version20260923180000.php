<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La programación de cada clase (fila 12 de la hoja de mejoras del profesorado): `topic` es la lista de
 * temas compartida por materia y nivel, que se crea al vuelo al programar; `lesson_plan` es lo que un
 * docente programa para UNA clase suya (día y tramo) y cómo fue.
 *
 * Una clase apunta a su tema por id y con ON DELETE SET NULL, así que reorganizar la lista nunca se lleva
 * lo registrado. Un docente que se va se lleva sus programaciones (CASCADE: son suyas y privadas); quien
 * creó un tema, no (SET NULL: el tema es de todos).
 */
final class Version20260923180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Programación de clases: temas compartidos (topic) y programación de cada clase (lesson_plan).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE topic (id INT AUTO_INCREMENT NOT NULL, subject VARCHAR(120) NOT NULL, level VARCHAR(16) DEFAULT NULL, name VARCHAR(120) NOT NULL, position SMALLINT NOT NULL, retired TINYINT(1) DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, created_by_id INT DEFAULT NULL, INDEX IDX_9D40DE1BB03A8386 (created_by_id), INDEX idx_topic_scope (subject, level), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE topic ADD CONSTRAINT FK_9D40DE1BB03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL');

        $this->addSql('CREATE TABLE lesson_plan (id INT AUTO_INCREMENT NOT NULL, lesson_date DATE NOT NULL, slot_index SMALLINT NOT NULL, group_names VARCHAR(160) NOT NULL, subject VARCHAR(120) NOT NULL, level VARCHAR(16) DEFAULT NULL, activity VARCHAR(16) DEFAULT NULL, outcome VARCHAR(16) DEFAULT NULL, note VARCHAR(160) DEFAULT NULL, updated_at DATETIME NOT NULL, teacher_id INT NOT NULL, topic_id INT DEFAULT NULL, INDEX IDX_E42B9D1941807E1D (teacher_id), INDEX IDX_E42B9D191F55203D (topic_id), INDEX idx_lesson_plan_continuity (teacher_id, subject, group_names), UNIQUE INDEX uniq_lesson_plan_class (teacher_id, lesson_date, slot_index), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE lesson_plan ADD CONSTRAINT FK_E42B9D1941807E1D FOREIGN KEY (teacher_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE lesson_plan ADD CONSTRAINT FK_E42B9D191F55203D FOREIGN KEY (topic_id) REFERENCES topic (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE lesson_plan DROP FOREIGN KEY FK_E42B9D1941807E1D');
        $this->addSql('ALTER TABLE lesson_plan DROP FOREIGN KEY FK_E42B9D191F55203D');
        $this->addSql('DROP TABLE lesson_plan');
        $this->addSql('ALTER TABLE topic DROP FOREIGN KEY FK_9D40DE1BB03A8386');
        $this->addSql('DROP TABLE topic');
    }
}
