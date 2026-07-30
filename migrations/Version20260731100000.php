<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cupos de guardias: cuántas guardias asume cada docente en un curso, según lo fije el equipo directivo.
 *
 * Una sola tabla y una sola perilla, porque las dos reglas del centro son la misma cosa vista de dos
 * maneras: quien está exento (orientación, PSC, equipo directivo) tiene cupo 0, y quien tiene menos
 * guardias por sus complementarias tiene cupo 1 o 2. Modelar la exención aparte obligaría a mantener dos
 * listas en paralelo, y encima no se podría derivar del catálogo de roles: no existe rol de orientación
 * ni de PSC.
 *
 * Guardias y recreos van en columnas distintas: salen de bolsas distintas y se cuentan con reglas
 * distintas (una guardia de recreo son un recreo grande y uno corto, incluso de días distintos), así que
 * una sola cifra obligaría al motor de propuesta a decidir el reparto entre ambas — justo la decisión que
 * el centro se reserva.
 *
 * Atada al curso: el horario y las complementarias se rehacen cada septiembre, así que el cupo del año
 * pasado no dice nada del de este.
 *
 * Nada que backfillar: la tabla nace vacía y la ausencia de fila se lee como cupo cero, que es el estado
 * de partida correcto — nadie tiene guardias asignadas hasta que el equipo directivo lo teclee.
 */
final class Version20260731100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cupos de guardias por docente y curso (guardia_quota).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE guardia_quota (id INT AUTO_INCREMENT NOT NULL, academic_year_id INT NOT NULL, teacher_id INT NOT NULL, lective_duties SMALLINT DEFAULT 0 NOT NULL, break_duties SMALLINT DEFAULT 0 NOT NULL, UNIQUE INDEX UNIQ_guardia_quota_year_teacher (academic_year_id, teacher_id), INDEX IDX_guardia_quota_year (academic_year_id), INDEX IDX_guardia_quota_teacher (teacher_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE guardia_quota ADD CONSTRAINT FK_guardia_quota_year FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE guardia_quota ADD CONSTRAINT FK_guardia_quota_teacher FOREIGN KEY (teacher_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE guardia_quota');
    }
}
