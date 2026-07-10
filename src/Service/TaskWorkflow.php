<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Task;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * The single place that resolves the state machine for a task. Both task workflows support
 * App\Entity\Task, so calling the Registry without a name is ambiguous and throws; every caller
 * must go through here so the workflow is always selected by the task's own type.
 */
final class TaskWorkflow
{
    public function __construct(private readonly Registry $registry)
    {
    }

    /**
     * The workflow that governs the given task, chosen by its {@see \App\Enum\TaskType}.
     *
     * @param Task $task the task
     *
     * @return WorkflowInterface the state machine for that task
     */
    public function for(Task $task): WorkflowInterface
    {
        return $this->registry->get($task, $task->getType()->workflowName());
    }
}
