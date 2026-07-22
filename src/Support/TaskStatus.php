<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Single source of truth for the human labels of a task's workflow state (the Symfony Workflow
 * marking, stored as a plain string on {@see \App\Entity\Task}). Shared by PHP (the activity
 * presenter) and Twig (the status_label macro reads {@see self::LABELS} via `constant()`), so the
 * Spanish wording lives in exactly one place.
 */
final class TaskStatus
{
    /** Workflow places (Symfony Workflow markings), matching config/packages/workflow.yaml. */
    public const string PENDING = 'pending';
    public const string SUBMITTED = 'submitted';
    public const string VALIDATED = 'validated';
    public const string CANCELLED = 'cancelled';

    /**
     * Terminal places: the task is closed and needs no further work — finalizada (validated) o
     * cancelada (cancelled). Fuente única para "ni abierta": la usan las queries de tareas abiertas
     * y el reparto de la agenda personal.
     */
    public const array CLOSED = [self::VALIDATED, self::CANCELLED];

    /**
     * Workflow place → human label (Spanish). Un único ciclo para todas las tareas: Pendiente →
     * Entregada → Finalizada, con Cancelada como cierre alternativo. "Devolver" no es un estado:
     * vuelve a Pendiente (el rechazo queda en el histórico), por eso no aparece aquí.
     */
    public const array LABELS = [
        self::PENDING => 'Pendiente',
        self::SUBMITTED => 'Entregada',
        self::VALIDATED => 'Finalizada',
        self::CANCELLED => 'Cancelada',
    ];

    /**
     * The human label for a workflow place, or the raw value if it is unknown.
     *
     * @param string $status the workflow place
     *
     * @return string the human label
     */
    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }
}
