<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Los dos momentos en los que el centro quiere que suene el recordatorio de una guardia: **la tarde
 * anterior y esa misma mañana** (decisión del equipo directivo, 30-07-2026).
 *
 * Son dos disparos y no uno configurable porque responden a cosas distintas: el de la tarde anterior te
 * deja preparar la clase desde casa —mirar si el grupo tiene tarea, coger una del banco— y el de la
 * mañana es el que evita que se te pase con el día ya empezado. Quien recibe el primero puede haberse
 * olvidado del segundo, así que ninguno sustituye al otro.
 *
 * El requisito duro que va con ellos es **no duplicar dentro de cada disparo**: nadie recibe dos avisos
 * de la misma guardia en el mismo momento. Eso se resuelve marcando cada guardia por disparo, y por eso
 * cada caso sabe en qué campo se apunta ({@see stampField()}). El acoplamiento con
 * {@see \App\Entity\GuardiaCover} es deliberado y está aquí a propósito: es el único `match` del asunto,
 * en vez de uno en el repositorio y otro en el servicio que pudieran discrepar.
 */
enum GuardiaReminderTrigger: string
{
    /** La tarde anterior: mira las guardias del DÍA SIGUIENTE. */
    case EVENING = 'evening';

    /** Esa misma mañana, antes de que empiecen las clases: mira las guardias de HOY. */
    case MORNING = 'morning';

    /**
     * El campo de {@see \App\Entity\GuardiaCover} donde se apunta que este disparo ya salió.
     *
     * @return string the entity field name (DQL), not the column
     */
    public function stampField(): string
    {
        return match ($this) {
            self::EVENING => 'eveningReminderSentAt',
            self::MORNING => 'morningReminderSentAt',
        };
    }

    /**
     * Cuántos días hay entre el día del barrido y el día de las guardias que mira.
     *
     * @return int 1 for the evening before, 0 for the same morning
     */
    public function daysAhead(): int
    {
        return match ($this) {
            self::EVENING => 1,
            self::MORNING => 0,
        };
    }

    /**
     * Cómo se refiere el aviso al día del que habla, para que el título se lea solo ("Mañana tienes
     * guardia" / "Hoy tienes guardia") sin que quien lo recibe tenga que mirar la fecha.
     *
     * @return string the Spanish day word, capitalised for a title
     */
    public function dayWord(): string
    {
        return match ($this) {
            self::EVENING => 'Mañana',
            self::MORNING => 'Hoy',
        };
    }
}
