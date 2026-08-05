<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El candado del acta publicada: cuándo se escribió el acta y las observaciones de quien la lee.
 *
 *  - `meeting.record_updated_at` es cuándo se escribió por última vez el acta —desarrollo, acuerdos y
 *    lista, que ahora se guardan de una vez ({@see \App\Entity\Meeting::recordSession()})—. Existe para
 *    poder contestar a una pregunta que la aplicación no sabía contestar: si el PDF que hay en el disco
 *    (y que la gente ya tiene por correo) sigue diciendo lo que dice el acta. Corregir un acta publicada
 *    está permitido y es lo que pidió el centro; lo que no puede pasar es que haya dos versiones y las dos
 *    se llamen «el acta».
 *
 *    **Las actas que ya existen se quedan con NULL a propósito.** NULL significa «nadie ha escrito el acta
 *    desde la aplicación», y sin sello no hay nada que comparar: `minutesOutdated()` da false y ninguna
 *    acta vieja aparece de golpe marcada como desfasada. Rellenarlo con `minutes_uploaded_at` sería
 *    inventarse que alguien escribió el desarrollo justo cuando se subió el fichero.
 *
 *  - `meeting_remark` son las observaciones a un acta PUBLICADA: quien asistió no reescribe el acta, pero
 *    sí puede decir que una línea no dice lo que se acordó. `ON DELETE CASCADE` sobre la reunión (sin el
 *    acta no significan nada, igual que su fichero) y `SET NULL` sobre la persona (borrar a alguien no
 *    borra la objeción que levantó).
 *
 * Nombres de columna elegidos para MariaDB 10.11, como en `Version20260805090000`: nada que un `ADD` pueda
 * parsear como el principio de otra cláusula (el `ADD period` que paró un despliegue el 31/07).
 */
final class Version20260805100001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reuniones: sello de escritura del acta y observaciones al acta publicada.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE meeting ADD record_updated_at DATETIME DEFAULT NULL');

        $this->addSql('CREATE TABLE meeting_remark (
            id INT AUTO_INCREMENT NOT NULL,
            meeting_id INT NOT NULL,
            author_id INT DEFAULT NULL,
            body LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_meeting_remark_meeting (meeting_id, created_at),
            INDEX IDX_meeting_remark_author (author_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE meeting_remark ADD CONSTRAINT FK_meeting_remark_meeting FOREIGN KEY (meeting_id) REFERENCES meeting (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE meeting_remark ADD CONSTRAINT FK_meeting_remark_author FOREIGN KEY (author_id) REFERENCES app_user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE meeting_remark DROP FOREIGN KEY FK_meeting_remark_meeting');
        $this->addSql('ALTER TABLE meeting_remark DROP FOREIGN KEY FK_meeting_remark_author');
        $this->addSql('DROP TABLE meeting_remark');
        $this->addSql('ALTER TABLE meeting DROP record_updated_at');
    }
}
