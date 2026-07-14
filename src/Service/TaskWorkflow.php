<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Task;
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
}
