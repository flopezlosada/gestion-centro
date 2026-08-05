<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sustituciones de profesorado de baja larga: `substitution`.
 *
 * Una fila autoriza el traspaso del horario, del cuadrante de recreo y de las guardias ya asignadas de
 * la persona de baja a quien la cubre ({@see \App\Guardia\SubstitutionApplier}). `ended_on` a NULL ES la
 * sustitución en vigor: abrirla traspasa y cerrarla devuelve, sin estado intermedio ni cron que la
 * active en su fecha.
 *
 * **Sin UNIQUE para "una abierta por persona", a propósito.** En MariaDB dos NULL no colisionan en un
 * índice único, así que un UNIQUE (substitute_id, ended_on) dejaría abrir todas las sustituciones que se
 * quisieran sobre la misma persona sin quejarse ni una vez — un índice que no impide nada y que se lee
 * como si lo hiciera. La comprobación vive en el applier, que además puede explicar cuál es la
 * sustitución que estorba en vez de devolver un 1062.
 *
 * Los nombres de columna están elegidos para MariaDB 10.11: nada de `period`, que en un `ADD` se parsea
 * como el inicio de `ADD PERIOD FOR` y paró un despliegue el 31/07 con 18 migraciones ya aplicadas.
 * `started_on`, `ended_on` y `note` no son reservadas.
 *
 * Las tres claves ajenas van con CASCADE: la fila solo tiene sentido mientras existan las dos personas y
 * el curso. Borrar una persona ya arrastra su horario (`schedule_entry.teacher_id` es CASCADE), así que
 * dejar aquí un registro huérfano de un traspaso que ya no se puede deshacer no serviría de nada.
 */
final class Version20260805090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sustituciones de baja larga: tabla substitution (ended_on NULL = en vigor).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE substitution (
            id INT AUTO_INCREMENT NOT NULL,
            academic_year_id INT NOT NULL,
            substituted_teacher_id INT NOT NULL,
            substitute_id INT NOT NULL,
            started_on DATE NOT NULL,
            ended_on DATE DEFAULT NULL,
            note VARCHAR(255) DEFAULT NULL,
            INDEX IDX_substitution_year (academic_year_id),
            INDEX IDX_substitution_substituted (substituted_teacher_id),
            INDEX IDX_substitution_substitute (substitute_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('ALTER TABLE substitution ADD CONSTRAINT FK_substitution_year FOREIGN KEY (academic_year_id) REFERENCES academic_year (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE substitution ADD CONSTRAINT FK_substitution_substituted FOREIGN KEY (substituted_teacher_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE substitution ADD CONSTRAINT FK_substitution_substitute FOREIGN KEY (substitute_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE substitution');
    }
}
