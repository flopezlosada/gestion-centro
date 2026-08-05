<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El candado del acta publicada: si el PDF sigue al día, cuándo salió el acta y las observaciones de quien
 * la lee.
 *
 *  - `meeting.minutes_stale` dice si el PDF que hay en el disco ya no coincide con lo que dice el acta,
 *    porque alguien escribió o corrigió el desarrollo, los acuerdos o la lista después de generarlo. Es un
 *    HECHO que apuntan las dos operaciones que lo saben, y no la comparación de dos sellos de tiempo, que
 *    fue la primera versión: con columnas `DATETIME` de resolución de un segundo, escribir el acta y
 *    generar su PDF dentro del mismo segundo comparaban IGUAL y el acta salía «al día» sin estarlo.
 *
 *    **Las actas que ya existen arrancan a 0 (al día).** No sabemos si alguien tocó su texto después de
 *    generarlas y suponer que sí pondría un aviso de corrección pendiente en cada reunión vieja del centro.
 *
 *  - `meeting.minutes_first_published_at` es cuándo salió el acta por PRIMERA vez, y a diferencia de
 *    `minutes_published_at` no se borra nunca. De ahí cuelgan el permiso de corrección y las observaciones:
 *    corregir un acta pasa por regenerar su PDF, y eso la devuelve a borrador a propósito, así que colgar
 *    el permiso de «está publicada AHORA» se lo quitaba a media corrección. **Las actas ya publicadas se
 *    rellenan con su `minutes_published_at`**, que es cuándo salieron; aquí sí hay dato y no hay nada que
 *    inventar.
 *
 *  - `meeting_remark` son las observaciones a un acta ya enviada: quien asistió no reescribe el acta, pero
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
        return 'Reuniones: PDF desfasado, primera publicación del acta y observaciones al acta.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE meeting ADD minutes_stale TINYINT(1) DEFAULT 0 NOT NULL, ADD minutes_first_published_at DATETIME DEFAULT NULL');
        // Lo que ya está publicado salió cuando dice su sello de publicación: no hay nada que inventar.
        $this->addSql('UPDATE meeting SET minutes_first_published_at = minutes_published_at WHERE minutes_published_at IS NOT NULL');

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
        $this->addSql('ALTER TABLE meeting DROP minutes_stale, DROP minutes_first_published_at');
    }
}
