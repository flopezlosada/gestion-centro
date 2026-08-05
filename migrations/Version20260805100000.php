<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tipo de reunión «Equipo docente»: el equipo de profesorado de un grupo, que se reúne para evaluarlo y
 * para acordar cómo llevarlo. Lo pidió el centro por su nombre.
 *
 * `INSERT IGNORE` como en `Version20260731170000`, que sembró el catálogo: reaplicar no duplica, y si el
 * centro ya lo había creado a mano desde administración esta migración no le pisa lo que tenga puesto (el
 * UNIQUE de `name` es lo que lo decide).
 *
 * **Aprobación del acta a 0.** Los órganos que aprueban su acta en la sesión siguiente son la CCP, el
 * claustro y el departamento (así se sembraron). Una sesión de evaluación firma su acta ahí mismo, así que
 * arrancar con la casilla marcada obligaría a desmarcarla en todas. Es solo el DEFECTO del tipo: la reunión
 * lleva su propia casilla y la excepción sigue estando a un clic.
 *
 * ⚠️ **Choque de nombres, resuelto en el mismo cambio.** `MeetingScope::STAFF` se mostraba «Con equipo
 * docente» y ahora se muestra «Con el profesorado». Sin ese renombrado, la pantalla de convocar tendría dos
 * cosas distintas con el mismo nombre a dos centímetros: el ÁMBITO (con quién es la reunión: profesorado,
 * alumnado o familias) y este TIPO (qué reunión de profesorado es). El renombrado es código, no datos, así
 * que va en `MeetingScope` y no aquí — pero los dos tienen que viajar juntos.
 */
final class Version20260805100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reuniones: tipo «Equipo docente» en el catálogo.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT IGNORE INTO meeting_type (name, minutes_approval_required, active) VALUES ('Equipo docente', 0, 1)");
    }

    public function down(Schema $schema): void
    {
        // Solo si no se ha usado: las actas ya archivadas bajo este tipo conservan su etiqueta, y borrar la
        // fila las dejaría sin ella (la FK es ON DELETE SET NULL, así que se perdería en silencio).
        $this->addSql("DELETE FROM meeting_type WHERE name = 'Equipo docente' AND id NOT IN (SELECT meeting_type_id FROM meeting WHERE meeting_type_id IS NOT NULL)");
    }
}
