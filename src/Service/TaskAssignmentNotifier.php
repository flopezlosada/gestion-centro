<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Task;
use App\Entity\User;

/**
 * Avisa a la persona a la que se le acaba de asignar una tarea (típicamente un superior creándola para
 * un subordinado). Decide a quién avisar y qué decirle; la entrega (aviso in-app + e-mail + push) la
 * hace {@see NotificationDispatcher}, compartida con el resto de notificadores.
 */
final class TaskAssignmentNotifier
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
    ) {
    }

    /**
     * Notifica al responsable de una tarea recién creada. No hace nada si la tarea no tiene un
     * responsable resoluble o si ese responsable es el propio creador (crearte una tarea a ti mismo no
     * necesita aviso): así un docente que se apunta su propia tarea no se auto-envía un correo.
     *
     * @param Task $task    the freshly created (and flushed) task
     * @param User $creator the user who created it
     */
    public function notifyCreated(Task $task, User $creator): void
    {
        $recipient = $task->resolveResponsible();
        if (null === $recipient || $recipient === $creator) {
            return;
        }

        $this->dispatcher->dispatch(
            $recipient,
            'task.assigned',
            sprintf('Nueva tarea: %s', $task->getTitle()),
            sprintf('%s te ha asignado una tarea. Vence el %s.', $creator->getFullName(), $task->getDueDate()->format('d/m/Y')),
            $task,
        );
    }
}
