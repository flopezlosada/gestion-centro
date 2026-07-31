<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Task;
use App\Entity\User;
use App\Service\OrganizationHierarchy;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\GuardEvent;

/**
 * Separation of duties on the single task workflow: the verdict transitions ("validate", "review" and
 * "reopen") may only be fired by a superior of the task's unit (up the chain of command) or an admin, and
 * never by the task's own assignee. The other transitions (submit = Entregar; cancel) are NOT restricted
 * here — that is handled where they are triggered (controller/voter), like the rest of the app.
 *
 * With ONE exception, at the very top of the chart: a task whose responsibility nobody outranks — the
 * dirección's own — has no possible validator, so requiring one left it waiting for somebody who does not
 * exist, and in practice only the TIC (a technical superuser) could close it. Nobody above you means you
 * close your own; that is why the same task also gets closed on delivery
 * ({@see TaskSelfClosingSubscriber}).
 */
#[AsEventListener(event: 'workflow.guard')]
final class TaskValidationGuardSubscriber
{
    public function __construct(
        private readonly Security $security,
        private readonly OrganizationHierarchy $hierarchy,
    ) {
    }

    public function __invoke(GuardEvent $event): void
    {
        $task = $event->getSubject();
        if (!$task instanceof Task) {
            return;
        }

        // Superior-only transitions (the verdict on someone else's work). Keep this list in sync with
        // TaskController::SUPERIOR_TRANSITIONS — both must agree on what counts as a superior action.
        if (!\in_array($event->getTransition()->getName(), ['validate', 'review', 'reopen'], true)) {
            return;
        }

        $actor = $this->security->getUser();
        if (!$actor instanceof User) {
            $event->setBlocked(true, 'Solo una persona identificada puede validar o devolver una tarea.');

            return;
        }

        // Separation of duties: you never judge the work YOU had to do. Measured against whoever holds
        // the task NOW ({@see Task::isOwnedBy()}), not against the titular assignee: on a delegated task
        // the doer is the delegatee, so comparing with getAssignedUser() got it backwards both ways —
        // it let the delegatee validate their own work and stopped the titular from judging it.
        // La cima de la jerarquía cierra (y reabre) lo suyo: si NADIE la supera por rango, exigir un
        // superior es exigir a alguien que no existe. Se comprueba antes que la separación de funciones
        // porque aquí no hay separación posible.
        if ([] === $this->hierarchy->managersAbove($task)) {
            return;
        }

        if ($task->isOwnedBy($actor)) {
            $event->setBlocked(true, 'No puedes validar ni devolver tu propia tarea.');

            return;
        }

        if ($this->security->isGranted('ROLE_ADMIN')) {
            return;
        }

        // The titular who delegated the work judges what their delegatee delivered: delegating hands
        // over the work, not the accountability. By rank alone they would never qualify (nobody
        // outranks themselves), yet it is precisely their own task.
        if (null !== $task->getDelegatedTo() && $actor === $task->getAssignedUser()) {
            return;
        }

        if (!$this->hierarchy->isSuperiorOfTask($actor, $task)) {
            $event->setBlocked(true, 'Solo un superior por rango puede validar o devolver esta tarea.');
        }
    }
}
