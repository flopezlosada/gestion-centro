<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Task;
use App\Enum\TaskType;
use App\Service\TaskWorkflow;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The task state machines must model progress (assignee) and validation (superior) as distinct
 * transitions, and the validate transition must be blocked unless a superior is authenticated.
 */
final class TaskWorkflowTest extends KernelTestCase
{
    private function newTask(TaskType $type): Task
    {
        return new Task('Memoria final', '2025-2026', new \DateTimeImmutable('2026-05-31'), $type);
    }

    private function taskWorkflow(): TaskWorkflow
    {
        /** @var TaskWorkflow */
        return self::getContainer()->get('test.task_workflow');
    }

    public function testDeliverableProgressTransitions(): void
    {
        self::bootKernel();
        $task = $this->newTask(TaskType::WITH_DELIVERABLE);
        $workflow = $this->taskWorkflow()->for($task);

        self::assertSame('pending', $task->getStatus());
        $workflow->apply($task, 'start');
        self::assertSame('in_progress', $task->getStatus());
        $workflow->apply($task, 'submit');
        self::assertSame('submitted', $task->getStatus());
    }

    public function testSimpleTaskCanBeCompletedByAssignee(): void
    {
        self::bootKernel();
        $task = $this->newTask(TaskType::SIMPLE);
        $workflow = $this->taskWorkflow()->for($task);

        self::assertTrue($workflow->can($task, 'complete'), 'the assignee can declare a simple task done');
        $workflow->apply($task, 'complete');
        self::assertSame('done', $task->getStatus());
    }

    public function testValidateIsBlockedWithoutAuthenticatedSuperior(): void
    {
        self::bootKernel();
        $task = $this->newTask(TaskType::WITH_DELIVERABLE);
        $workflow = $this->taskWorkflow()->for($task);
        $workflow->apply($task, 'start');
        $workflow->apply($task, 'submit');

        self::assertFalse($workflow->can($task, 'validate'), 'validation must require an authenticated superior');
    }
}
