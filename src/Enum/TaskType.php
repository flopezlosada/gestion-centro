<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The kind of task. TODAS las tareas comparten UN único ciclo de vida (Pendiente → Entregada →
 * Finalizada, con Cancelada aparte; ver config/packages/workflow.yaml). El tipo ya NO elige máquina de
 * estados: solo indica si al "Entregar" hay que adjuntar la referencia de un documento
 * ({@see \App\Entity\Task::requiresDocument()}).
 */
enum TaskType: string
{
    /** Se entrega y un superior la valida (sin documento). */
    case SIMPLE = 'simple';

    /** Igual, pero al entregar se adjunta la referencia de un documento. */
    case WITH_DELIVERABLE = 'with_deliverable';

    /**
     * Human-facing label (Spanish).
     *
     * @return string the type label
     */
    public function label(): string
    {
        return match ($this) {
            self::SIMPLE => 'Tarea simple',
            self::WITH_DELIVERABLE => 'Tarea con entregable',
        };
    }

    /**
     * The initial state (place) a new task starts in. Un único ciclo de vida para todas las tareas
     * (ver config/packages/workflow.yaml), así que el tipo ya no elige máquina de estados: solo indica
     * si "Entregar" exige adjuntar un documento ({@see \App\Entity\Task::requiresDocument()}).
     *
     * @return string the initial place
     */
    public function initialPlace(): string
    {
        return 'pending';
    }
}
