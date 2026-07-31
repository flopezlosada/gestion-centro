<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Task;
use App\Entity\User;

/**
 * Tells the OTHER side that a task moved. It is what turns the delivery cycle into a conversation
 * instead of two people refreshing a screen: the centre described it step by step — se entrega y quien
 * la mandó "recibe una alerta para que la revise"; se devuelve y "la persona recibe esta alerta y revisa
 * los comentarios"; se finaliza y "el profesor que realizó la tarea recibe un aviso para que sepa que ya
 * está terminada".
 *
 * Two rules hold for every step:
 *  - nobody is notified of their own action (you know what you just did);
 *  - the notice carries the comment when there is one, because a "te la han devuelto" that does not say
 *    what to change forces a trip into the app to find out, which is the friction this replaces.
 *
 * Who to tell is derived, never passed in: the delivery goes UP to whoever has to judge it (the task's
 * creator, falling back to whoever it is assigned to when the task was seeded with no author), and the
 * verdict comes DOWN to whoever holds the task now.
 */
final class TaskProgressNotifier
{
    public function __construct(private readonly NotificationDispatcher $dispatcher)
    {
    }

    /**
     * Notifies the other side of a lifecycle transition, if there is anyone to notify.
     *
     * @param Task        $task       the task, already moved and flushed
     * @param string      $transition the workflow transition just applied
     * @param User        $actor      who fired it (never notified)
     * @param string|null $comment    what they wrote with it, if anything
     */
    public function notify(Task $task, string $transition, User $actor, ?string $comment = null): void
    {
        [$recipient, $title, $body] = match ($transition) {
            'submit' => [
                $task->getCreatedBy() ?? $task->getAssignedUser(),
                sprintf('Tarea entregada: %s', $task->getTitle()),
                sprintf('%s ha entregado «%s». Cuando puedas, revísala.', $actor->getFullName(), $task->getTitle()),
            ],
            'review' => [
                $task->resolveResponsible(),
                sprintf('Te devuelven una tarea: %s', $task->getTitle()),
                sprintf('%s ha revisado «%s» y hay cosas que cambiar.', $actor->getFullName(), $task->getTitle()),
            ],
            'validate' => [
                $task->getCompletedBy() ?? $task->resolveResponsible(),
                sprintf('Tarea finalizada: %s', $task->getTitle()),
                sprintf('%s ha dado por finalizada «%s». Ya no tienes que hacer nada.', $actor->getFullName(), $task->getTitle()),
            ],
            default => [null, '', ''],
        };

        if (!$recipient instanceof User || $recipient === $actor) {
            return;
        }

        $this->dispatcher->dispatch(
            $recipient,
            'task.'.$transition,
            $title,
            null !== $comment ? $body."\n\n".$comment : $body,
            $task,
        );
    }
}
