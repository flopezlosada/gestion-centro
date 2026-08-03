<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Entity\SpacePlan;

/**
 * Lo que el programa PROPONE para un día de exámenes, hora por hora, para que el equipo directivo lo valide
 * o lo retoque ({@see ExamPeriodRelief} lo calcula).
 *
 * Lleva los planes de los que sale, y no solo el resultado, porque la pantalla tiene que poder decir de
 * dónde viene: sin nombrar el plan aprobado y los grupos que se examinan, una lista de gente "libre" es una
 * afirmación sin respaldo, y quien valida no puede validar lo que no puede comprobar.
 */
final readonly class ExamPeriodProposal
{
    /**
     * @param list<SpacePlan>       $plans   los planes aprobados que ese día sacan grupos del horario
     * @param list<ExamPeriodSlot>  $periods las horas afectadas, la primera antes
     */
    public function __construct(
        public array $plans,
        public array $periods,
    ) {
    }

    /**
     * Si ese día hay algo de esto en marcha. Es lo que hace la pantalla "activable" sin inventar un
     * interruptor aparte: el interruptor es aprobar el plan de exámenes, que ya existe y ya lo maneja el
     * equipo directivo. Dos interruptores para lo mismo solo sirven para quedarse desparejados.
     *
     * @return bool true when an approved plan takes groups out of the timetable that day
     */
    public function isActive(): bool
    {
        return [] !== $this->plans;
    }

    /**
     * Los grupos que se examinan ese día, para decirlo en pantalla.
     *
     * @return list<string> the group names named by the plans, without repeats
     */
    public function examGroups(): array
    {
        $groups = [];
        foreach ($this->plans as $plan) {
            foreach ($plan->getScopeGroupNames() as $group) {
                $groups[$group] = $group;
            }
        }
        ksort($groups);

        return array_values($groups);
    }

    /**
     * Las horas que merece la pena pintar: las que tienen a alguien acompañando, a alguien libre o algo sin
     * cubrir.
     *
     * @return list<ExamPeriodSlot> the periods worth showing
     */
    public function relevantPeriods(): array
    {
        return array_values(array_filter($this->periods, static fn (ExamPeriodSlot $slot): bool => $slot->isRelevant()));
    }

    /**
     * Cuántas guardias hay que pasar a otra persona en todo el día, y cuánta gente hay libre para cogerlas:
     * el titular de la pantalla.
     *
     * @return array{handOver: int, available: int, uncovered: int} the day's headline figures
     */
    public function summary(): array
    {
        $handOver = 0;
        $available = 0;
        $uncovered = 0;
        foreach ($this->periods as $slot) {
            $handOver += $slot->guardiasToHandOver();
            $available += \count($slot->proposable());
            $uncovered += $slot->uncovered;
        }

        return ['handOver' => $handOver, 'available' => $available, 'uncovered' => $uncovered];
    }
}
