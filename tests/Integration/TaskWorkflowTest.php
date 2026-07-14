<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Task;
use App\Enum\TaskType;
use App\Service\TaskWorkflow;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The single task lifecycle (Pendiente → Entregada → Finalizada, con Cancelada aparte) is shared by
 * every task regardless of type; "validate" must be blocked unless a superior is authenticated.
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

    public function testSubmitTakesATaskToEntregada(): void
    {
        self::bootKernel();
        $task = $this->newTask(TaskType::WITH_DELIVERABLE);
        $workflow = $this->taskWorkflow()->for($task);

        self::assertSame('pending', $task->getStatus());
        $workflow->apply($task, 'submit');
        self::assertSame('submitted', $task->getStatus());
    }

    public function testSimpleAndDeliverableShareTheSameLifecycle(): void
    {
        self::bootKernel();
        $simple = $this->newTask(TaskType::SIMPLE);
        $deliverable = $this->newTask(TaskType::WITH_DELIVERABLE);

        self::assertTrue($this->taskWorkflow()->for($simple)->can($simple, 'submit'), 'una tarea simple también se entrega');
        self::assertTrue($this->taskWorkflow()->for($deliverable)->can($deliverable, 'submit'));
    }

    public function testValidateIsBlockedWithoutAuthenticatedSuperior(): void
    {
        self::bootKernel();
        $task = $this->newTask(TaskType::WITH_DELIVERABLE);
        $workflow = $this->taskWorkflow()->for($task);
        $workflow->apply($task, 'submit');

        self::assertFalse($workflow->can($task, 'validate'), 'validation must require an authenticated superior');
    }

    public function testATaskCanBeCancelledFromPending(): void
    {
        self::bootKernel();
        $task = $this->newTask(TaskType::SIMPLE);
        $workflow = $this->taskWorkflow()->for($task);

        self::assertTrue($workflow->can($task, 'cancel'), 'una tarea pendiente se puede cancelar');
        $workflow->apply($task, 'cancel');
        self::assertSame('cancelled', $task->getStatus());
    }
}
