<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Agenda\AgendaEntry;
use App\Entity\Task;
use App\Enum\TaskType;
use App\Support\TaskStatus;
use PHPUnit\Framework\TestCase;

/**
 * The lifecycle predicates a task exposes to the UI: pendiente (admite delegación), cerrada (solo
 * lectura) y hecha (lo que pinta la agenda). "Hecha" cubre las DOS formas de estarlo — casilla de
 * progreso marcada o tarea ya Finalizada — porque el bucket "Hechas" y la casilla de la fila usan el
 * mismo flag y no pueden discrepar.
 */
final class TaskLifecycleStateTest extends TestCase
{
    private function task(TaskType $type = TaskType::SIMPLE): Task
    {
        return new Task('Memoria', '2025-2026', new \DateTimeImmutable('2026-05-31'), $type);
    }

    public function testANewSimpleTaskIsPendingOpenAndNotDone(): void
    {
        $task = $this->task();

        self::assertTrue($task->isPending());
        self::assertFalse($task->isClosed());
        self::assertFalse($task->isDone());
    }

    public function testASubmittedTaskIsNoLongerPendingButStillOpen(): void
    {
        // Entregada: ya no admite cambio de titular (no se delega), pero no está cerrada ni hecha.
        $task = $this->task(TaskType::WITH_DELIVERABLE);
        $task->setStatus(TaskStatus::SUBMITTED);

        self::assertFalse($task->isPending());
        self::assertFalse($task->isClosed());
        self::assertFalse($task->isDone());
    }

    public function testAValidatedTaskCountsAsDoneEvenWithoutTickingTheCheckbox(): void
    {
        // El caso del bug: finalizada por el superior, sin que el responsable marcara la casilla. Salía
        // en "Hechas" con el círculo vacío, como si siguiera pendiente.
        $task = $this->task();
        $task->setStatus(TaskStatus::VALIDATED);

        self::assertFalse($task->isCheckboxDone());
        self::assertTrue($task->isDone());
        self::assertTrue($task->isClosed());
        self::assertTrue(AgendaEntry::fromTask($task)->done);
    }

    public function testTickingTheCheckboxMakesItDoneWhileStillPending(): void
    {
        $task = $this->task();
        $task->setCheckboxDone(true);

        self::assertTrue($task->isDone());
        self::assertTrue($task->isPending(), 'marcar la casilla no cierra la tarea: la cierra la validación');
        self::assertFalse($task->isClosed());
        self::assertTrue(AgendaEntry::fromTask($task)->done);
    }

    public function testACancelledTaskIsClosedButNotDone(): void
    {
        // Cancelada no es un logro: cerrada sí, hecha no (a la agenda ni llega, la query la excluye).
        $task = $this->task();
        $task->setStatus(TaskStatus::CANCELLED);

        self::assertTrue($task->isClosed());
        self::assertFalse($task->isDone());
        self::assertFalse($task->isPending());
    }

    public function testADeliverableTaskHasNoQuickCheckboxSoItCannotBeClosedSkippingTheDocument(): void
    {
        // El bug: el alta dejaba requiresCheckbox=true también en una tarea con entregable, y la casilla
        // rápida la cerraba saltándose el documento y el flujo Entregar→Validar. La casilla NO debe existir.
        $task = $this->task(TaskType::WITH_DELIVERABLE)->setRequiresDocument(true)->setRequiresCheckbox(true);

        self::assertFalse($task->requiresCheckbox(), 'una tarea con entregable no tiene casilla rápida');
    }

    public function testAStaleCheckboxOnADeliverableTaskDoesNotCountAsDone(): void
    {
        // Datos heredados del bug: checkboxDone=true en una tarea con entregable. No debe darla por hecha;
        // solo la cierra la Finalización.
        $task = $this->task(TaskType::WITH_DELIVERABLE)->setRequiresDocument(true)->setCheckboxDone(true);

        self::assertFalse($task->isDone(), 'un checkbox obsoleto en una con entregable no la da por hecha');
        self::assertFalse(AgendaEntry::fromTask($task)->done);
    }
}
