<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The kind of task, which selects its state-machine lifecycle. Kept small on purpose (KISS): a
 * task either is a simple "do it and get it validated" item, or it carries a deliverable with an
 * intermediate progress/submission step. New lifecycles are added here and in workflow.yaml.
 */
enum TaskType: string
{
    /** pending → done → validated. */
    case SIMPLE = 'simple';

    /** pending → in_progress → submitted → validated (or rejected back to in_progress). */
    case WITH_DELIVERABLE = 'with_deliverable';

    /**
     * Human-facing label (Spanish).
     *
     * @return string the type label
     */
    public function label(): string
    {
        return match ($this) {
            self::SIMPLE => 'Tarea simple',
            self::WITH_DELIVERABLE => 'Tarea con entregable',
        };
    }

    /**
     * Name of the Symfony Workflow that governs tasks of this type (see config/packages/workflow.yaml).
     *
     * @return string the workflow name
     */
    public function workflowName(): string
    {
        return match ($this) {
            self::SIMPLE => 'task_simple',
            self::WITH_DELIVERABLE => 'task_with_deliverable',
        };
    }

    /**
     * The initial state (place) a new task of this type starts in.
     *
     * @return string the initial place
     */
    public function initialPlace(): string
    {
        return 'pending';
    }
}
