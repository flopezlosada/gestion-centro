<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Task;
use App\Support\TaskStatus;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * The single place that resolves the state machine for a task. There is ONE workflow ("task") for
 * every task regardless of type; callers go through here so the workflow name lives in one spot.
 */
final class TaskWorkflow
{
    /** The one and only task workflow (see config/packages/workflow.yaml). */
    public const string NAME = 'task';

    public function __construct(private readonly Registry $registry)
    {
    }

    /**
     * The workflow that governs the given task.
     *
     * @param Task $task the task
     *
     * @return WorkflowInterface the state machine for the task
     */
    public function for(Task $task): WorkflowInterface
    {
        return $this->registry->get($task, self::NAME);
    }

    /**
     * Whether the task is waiting for the CURRENT user's verdict: it is Entregada and the workflow lets
     * them validate it (the guard decides, reading who is authenticated — see
     * {@see \App\EventSubscriber\TaskValidationGuardSubscriber}). The single definition of "esperando mi
     * validación", shared by the named view of the task list and the department module of Inicio, which
     * had the same predicate written twice.
     *
     * It deliberately does NOT include the Pendientes a superior could now close ("Dar por finalizada"):
     * dirección may close ANY open task of the school, so counting those would turn an inbox of real
     * work into the whole course plan. That shortcut is meant to be reached from the task itself.
     *
     * @param Task $task the task
     *
     * @return bool true if the task awaits this user's validation
     */
    public function isAwaitingVerdict(Task $task): bool
    {
        return TaskStatus::SUBMITTED === $task->getStatus() && $this->for($task)->can($task, 'validate');
    }
}
