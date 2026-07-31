<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Task;
use App\Service\OrganizationHierarchy;
use App\Service\TaskWorkflow;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\EnteredEvent;

/**
 * Closes on delivery the tasks that NOBODY can validate: the ones at the very top of the chart, whose
 * responsibility no rank outranks (dirección's own work).
 *
 * Decided with Paco on 2026-07-31, after the review found four tasks of Dirección sitting in Entregada
 * with no possible validator: the rule "a superior signs off your work" has no superior to offer there, so
 * they waited for somebody who does not exist and the only one who could close them was the TIC, by being
 * a technical superuser. Having the head of the school depend on the IT coordinator to close her own work
 * is not a defensible model, and "que se cierren solas al entregarlas" is the centre's own preference —
 * revisable if they say otherwise.
 *
 * Runs on `workflow.entered` for `submitted`, not inside the controller, so it applies however the task
 * got delivered (screen, future API, a batch). {@see TaskValidationGuardSubscriber} lets this same case
 * through by hand as well, and {@see TaskCompletionSubscriber} freezes who did it, exactly as with any
 * other validated task.
 */
#[AsEventListener(event: 'workflow.entered')]
final class TaskSelfClosingSubscriber
{
    public function __construct(
        private readonly OrganizationHierarchy $hierarchy,
        private readonly TaskWorkflow $workflows,
    ) {
    }

    public function __invoke(EnteredEvent $event): void
    {
        $task = $event->getSubject();
        if (!$task instanceof Task || !$event->getMarking()->has('submitted')) {
            return;
        }

        // Alguien por encima = alguien a quien esperar. Se pregunta a la jerarquía, no al rango del actor:
        // lo que decide es la tarea, no quién la entrega.
        if ([] !== $this->hierarchy->managersAbove($task)) {
            return;
        }

        $workflow = $this->workflows->for($task);
        // `can()` antes de `apply()`: si algún guard futuro bloqueara este cierre, la entrega no debe
        // reventar con una excepción — la tarea se queda Entregada, que es el estado honesto.
        if ($workflow->can($task, 'validate')) {
            $workflow->apply($task, 'validate');
        }
    }
}
