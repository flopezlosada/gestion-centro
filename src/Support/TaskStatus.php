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
    /** Workflow place → human label (Spanish). */
    public const array LABELS = [
        'pending' => 'Pendiente',
        'in_progress' => 'En curso',
        'submitted' => 'Enviada',
        'done' => 'Hecha',
        'validated' => 'Validada',
        'rejected' => 'Rechazada',
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
