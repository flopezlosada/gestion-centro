<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Task;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\EnteredEvent;

/**
 * Freezes who actually did a task the moment it reaches the terminal "validated" state (the single
 * terminal place of both task workflows). {@see Task::$completedBy} is a historical fact: once set it
 * is never rewritten, so a later change of the responsibility holder (or of a unit's manager) does
 * not retro-reassign closed tasks. The change rides on the same flush that applies the transition.
 */
#[AsEventListener(event: 'workflow.entered')]
final class TaskCompletionSubscriber
{
    public function __invoke(EnteredEvent $event): void
    {
        $task = $event->getSubject();
        if (!$task instanceof Task) {
            return;
        }

        // Both task workflows share "validated" as their only terminal place.
        if (!$event->getMarking()->has('validated')) {
            return;
        }

        // Freeze once: never overwrite a fact already recorded.
        if (null !== $task->getCompletedBy()) {
            return;
        }

        $task->setCompletedBy($task->resolveResponsible());
    }
}
