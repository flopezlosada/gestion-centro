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
 * Guards the task state machines. Two things:
 *
 *  1. Integrity: both task workflows support App\Entity\Task and share place names (pending,
 *     validated), so applying the wrong workflow to a task could move it to a place of the other
 *     lifecycle. Any transition whose workflow does not match the task's own type is blocked.
 *  2. Separation of duties: the superior's verdict transitions ("validate" and "reject") may only be
 *     fired by a superior of the task's unit (up the chain of command) or an admin, and never by the
 *     task's own assignee. Progress transitions (start/submit/complete/…) are NOT restricted here —
 *     that is handled where they are triggered (controller/voter), like the rest of the app.
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

        // Integrity: never let a workflow drive a task of a different type (shared place names would
        // otherwise let some transitions through and corrupt the status).
        if ($event->getWorkflowName() !== $task->getType()->workflowName()) {
            $event->setBlocked(true, 'El flujo no corresponde al tipo de la tarea.');

            return;
        }

        // Superior-only transitions (the verdict on someone else's work). Keep this list in sync with
        // TaskController::SUPERIOR_TRANSITIONS — both must agree on what counts as a superior action.
        if (!\in_array($event->getTransition()->getName(), ['validate', 'reject'], true)) {
            return;
        }

        $actor = $this->security->getUser();
        if (!$actor instanceof User) {
            $event->setBlocked(true, 'Solo una persona identificada puede validar o devolver una tarea.');

            return;
        }

        // Separation of duties: you never validate/reject your own task, even if you manage its unit.
        if ($actor === $task->getAssignedUser()) {
            $event->setBlocked(true, 'No puedes validar ni devolver tu propia tarea.');

            return;
        }

        if ($this->security->isGranted('ROLE_ADMIN')) {
            return;
        }

        if (!$this->hierarchy->isSuperiorOf($actor, $task->getUnit())) {
            $event->setBlocked(true, 'Solo un superior de la unidad puede validar o devolver esta tarea.');
        }
    }
}
