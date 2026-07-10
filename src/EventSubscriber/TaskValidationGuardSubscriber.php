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
 * Enforces the separation between progress and validation: the "validate" transition of any task
 * workflow may only be fired by a superior of the task's unit (up the chain of command) or by an
 * admin. Progress transitions (complete/submit/…) are left to the assignee and are not guarded here.
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
        if ('validate' !== $event->getTransition()->getName()) {
            return;
        }

        $task = $event->getSubject();
        if (!$task instanceof Task) {
            return;
        }

        $actor = $this->security->getUser();
        if (!$actor instanceof User) {
            $event->setBlocked(true, 'Solo una persona identificada puede validar una tarea.');

            return;
        }

        if ($this->security->isGranted('ROLE_ADMIN')) {
            return;
        }

        if (!$this->hierarchy->isSuperiorOf($actor, $task->getUnit())) {
            $event->setBlocked(true, 'Solo un superior de la unidad puede validar esta tarea.');
        }
    }
}
