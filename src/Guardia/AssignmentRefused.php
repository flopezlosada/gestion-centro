<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Entity\GuardiaCover;
use App\Entity\User;

/**
 * A manual assignment from the parte that {@see GuardiaScheduler::assign()} will not carry out. It
 * exists so the refusal reaches the coordinator as the sentence they need to read, instead of the
 * controller having to reconstruct why from a boolean.
 */
final class AssignmentRefused extends \RuntimeException
{
    /**
     * The cover already has someone assigned: changing that is an edit, and an edit records a reason.
     */
    public static function alreadyCovered(GuardiaCover $cover): self
    {
        return new self(sprintf(
            '%s ya está cubierta por %s. Para cambiarlo usa "Modificar", que pide el motivo del cambio.',
            $cover->getGroupName() ?? 'Esta ausencia',
            $cover->getAssignedGuardia()?->getFullName() ?? 'otro profesor',
        ));
    }

    /**
     * The teacher is no longer in the pool: absent that period, or already covering another group.
     */
    public static function notAvailable(User $teacher): self
    {
        return new self(sprintf(
            '%s ya no puede cubrir esta hora: falta hoy o ha pasado a cubrir otro grupo. Vuelve a abrir la hoja para ver quién queda libre.',
            $teacher->getFullName(),
        ));
    }
}
