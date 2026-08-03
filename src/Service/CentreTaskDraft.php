<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Task;

/**
 * Una fila del catálogo de tareas del centro ya convertida en {@see Task}, todavía sin persistir, más
 * las dos cosas que quien la persiste necesita saber de ella.
 *
 * {@see $deadlineDerived} es la importante y no es un detalle: el catálogo dice CUÁNDO en texto libre
 * («Inicio de curso», «Cada evaluación», «A lo largo del curso»), y solo algunas de esas formas se
 * pueden anclar de verdad al calendario del curso. Cuando no se puede, la fecha es un reparto a lo
 * largo del año — un relleno, no una fecha del centro — y quien importa tiene que poder decirlo en voz
 * alta, porque de esas fechas saldrán recordatorios por correo y por móvil.
 */
final readonly class CentreTaskDraft
{
    /**
     * La responsabilidad NO se repite aquí: ya cuelga de la tarea
     * ({@see Task::getResponsibility()}), y tenerla en dos sitios solo da una copia que se puede
     * desincronizar.
     *
     * @param string $catalogId       identificador de la fila en el catálogo (p. ej. "A1-01")
     * @param Task   $task            la tarea lista para persistir, sin estado de flujo
     * @param bool   $deadlineDerived si la fecha límite se deduce del catálogo (true) o es un relleno (false)
     */
    public function __construct(
        public string $catalogId,
        public Task $task,
        public bool $deadlineDerived,
    ) {
    }
}
