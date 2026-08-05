<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Entity\Substitution;
use App\Entity\User;

/**
 * Un alta de sustitución que {@see SubstitutionApplier::open()} no va a hacer. Existe para que la
 * negativa llegue a la pantalla como la frase que hay que leer, y no como un booleano que el
 * controlador tenga que traducir de vuelta a un porqué.
 */
final class SubstitutionRefused extends \RuntimeException
{
    /**
     * Alguien no puede sustituirse a sí mismo. Suena absurdo hasta que el desplegable se deja como
     * estaba y las dos casillas apuntan a la misma persona: el traspaso sería un UPDATE de una fila
     * sobre sí misma y la pantalla diría que todo ha ido bien.
     */
    public static function samePerson(User $person): self
    {
        return new self(sprintf('%s no puede sustituirse a sí misma. Elige a otra persona.', $person->getFullName()));
    }

    /**
     * Una de las dos ya está dentro de otra sustitución en vigor. Encadenarlas dejaría un horario en
     * manos de quien ya cedió el suyo, y cerrar cualquiera de las dos devolvería filas que no le tocan.
     */
    public static function alreadyInvolved(User $person, Substitution $open): self
    {
        return new self(sprintf(
            '%s ya está en una sustitución sin cerrar (%s sustituye a %s desde el %s). Ciérrala antes de abrir otra.',
            $person->getFullName(),
            $open->getSubstitute()->getFullName(),
            $open->getSubstitutedTeacher()->getFullName(),
            $open->getStartedOn()->format('d/m/Y'),
        ));
    }

    /**
     * Quien va a sustituir ya tiene horario propio en ese curso. Traspasarle otro encima le dejaría dos
     * a la vez —dos veces en el pool de guardias, dos clases a la misma hora— y sin nada que distinga
     * las suyas de las heredadas a la hora de devolverlas.
     */
    public static function substituteHasTimetable(User $substitute, int $cells): self
    {
        return new self(sprintf(
            '%s ya tiene horario en este curso (%d celdas). Traspasarle otro encima dejaría dos horarios sumados. Si esta persona ya estaba dando clase, la sustitución no es la vía.',
            $substitute->getFullName(),
            $cells,
        ));
    }

    /**
     * Quien va a sustituir ya tiene plazas en el cuadrante de recreo, con el mismo problema: sumar las
     * de la persona sustituida chocaría con el UNIQUE (curso, docente, día, recreo) en cuanto coincida
     * un solo tramo, y saldría como un error de base de datos en mitad del traspaso.
     */
    public static function substituteHasBreakDuties(User $substitute, int $places): self
    {
        return new self(sprintf(
            '%s ya tiene %d plaza(s) en el cuadrante de recreo de este curso. Quítaselas antes de darle la sustitución.',
            $substitute->getFullName(),
            $places,
        ));
    }
}
