<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Notification;
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
        $this->notifyCreatedBatch([$task], $creator);
    }

    /**
     * Lo mismo para VARIAS tareas creadas de una vez — mandar la misma tarea a un departamento entero o
     * a todo el claustro genera una por persona ({@see \App\Controller\TaskController::new()}).
     *
     * Existe porque hacerlo en un bucle sobre {@see notifyCreated()} no era lo mismo: cada llamada
     * termina en {@see NotificationDispatcher::dispatch()}, que hace su propio flush Y manda el correo
     * ahí mismo. Con el claustro entero eso son ochenta flushes y ochenta envíos SMTP seguidos dentro de
     * la misma petición: lenta, y si PHP corta a mitad las tareas quedan creadas pero media plantilla sin
     * enterarse, en silencio. Aquí se acumulan los avisos, se hace UN flush y se entrega el lote.
     *
     * @param list<Task> $tasks   the freshly created (and flushed) tasks
     * @param User       $creator the user who created them
     */
    public function notifyCreatedBatch(array $tasks, User $creator): void
    {
        /** @var list<Notification> $notifications */
        $notifications = [];
        foreach ($tasks as $task) {
            $recipient = $task->resolveResponsible();
            if (null === $recipient || $recipient === $creator) {
                continue;
            }

            $notifications[] = $this->dispatcher->record(
                $recipient,
                'task.assigned',
                sprintf('Nueva tarea: %s', $task->getTitle()),
                sprintf('%s te ha asignado una tarea. Vence el %s.', $creator->getFullName(), $task->getDueDate()->format('d/m/Y')),
                $task,
            );
        }

        if ([] === $notifications) {
            return;
        }

        $this->dispatcher->flushAndSend($notifications);
    }
}
