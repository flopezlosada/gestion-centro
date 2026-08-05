<?php

declare(strict_types=1);

namespace App\Guardia;

/**
 * Qué se movió de verdad al abrir o cerrar una sustitución.
 *
 * Se devuelve para que la pantalla lo cuente en vez de decir "hecho": un traspaso que sale bien y mueve
 * cero celdas —porque el horario de esa persona todavía no se ha importado— es indistinguible de uno
 * que funciona, y quien lo pulsa se iría convencido de que quien sustituye ya está en el reparto.
 */
final readonly class SubstitutionResult
{
    /**
     * @param int $timetableCells  celdas de horario que cambiaron de manos (clases y guardias juntas)
     * @param int $breakDutyPlaces plazas del cuadrante de recreo traspasadas
     * @param int $guardiaCovers   guardias ya asignadas que cambiaron de manos (solo al abrir; nunca de
     *                             días pasados)
     */
    public function __construct(
        public int $timetableCells,
        public int $breakDutyPlaces,
        public int $guardiaCovers = 0,
    ) {
    }

    /**
     * Si el traspaso no encontró nada que mover. La pantalla lo dice con todas las letras, porque el
     * caso normal que lo produce tiene arreglo: el horario del curso aún no está importado.
     *
     * @return bool true cuando no se movió absolutamente nada
     */
    public function isEmpty(): bool
    {
        return 0 === $this->timetableCells && 0 === $this->breakDutyPlaces && 0 === $this->guardiaCovers;
    }
}
