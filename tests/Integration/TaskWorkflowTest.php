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

    /**
     * Cancelar sale de todo lo ABIERTO, también de lo ya entregado: una actividad que se anula en enero
     * puede tener trabajo entregado detrás. Lo que el centro quitó fue cancelar una tarea fuera de plazo,
     * y eso se mide contra la FECHA (lo comprueba TaskController), no contra el estado.
     */
    public function testCancellingIsPossibleFromEveryOpenState(): void
    {
        self::bootKernel();
        $task = $this->newTask(TaskType::WITH_DELIVERABLE);
        $workflow = $this->taskWorkflow()->for($task);

        $workflow->apply($task, 'submit');
        self::assertTrue($workflow->can($task, 'cancel'), 'una entregada se puede anular');

        $task->setStatus('in_review');
        self::assertTrue($workflow->can($task, 'cancel'), 'una devuelta también');

        $task->setStatus('validated');
        self::assertFalse($workflow->can($task, 'cancel'), 'una finalizada ya está cerrada');
    }

    /**
     * El ciclo de ida y vuelta que pidió el centro: se entrega, se devuelve para revisar, se corrige y se
     * vuelve a entregar. Sin tope de vueltas — "puede haber tanta retroalimentación como sea necesaria" —,
     * así que se comprueban dos.
     */
    public function testATaskCanGoBackAndForthAsManyTimesAsNeeded(): void
    {
        self::bootKernel();
        $task = $this->newTask(TaskType::WITH_DELIVERABLE);
        $workflow = $this->taskWorkflow()->for($task);

        for ($round = 1; $round <= 2; ++$round) {
            $workflow->apply($task, 'submit');
            self::assertSame('submitted', $task->getStatus(), sprintf('vuelta %d: entregada', $round));

            // "review" es de superior (lo guarda TaskValidationGuardSubscriber), así que aquí se mueve a
            // mano: lo que este test comprueba es la MÁQUINA DE ESTADOS, no quién puede empujarla.
            $task->setStatus('in_review');
            self::assertTrue($workflow->can($task, 'submit'), sprintf('vuelta %d: se puede volver a entregar', $round));
        }
    }

    /**
     * Devolver una tarea NO la manda de vuelta a Pendiente. Son cosas distintas —una Pendiente no la ha
     * tocado nadie y una devuelta ya se entregó y lleva escrito qué cambiar— y con las dos en el mismo
     * sitio ni el listado ni el aviso podían distinguirlas.
     */
    public function testReviewLandsInItsOwnPlaceAndNotBackInPending(): void
    {
        self::bootKernel();
        $task = $this->newTask(TaskType::WITH_DELIVERABLE);
        $definition = $this->taskWorkflow()->for($task)->getDefinition();

        self::assertContains('in_review', $definition->getPlaces());
        $review = array_values(array_filter(
            $definition->getTransitions(),
            static fn ($t): bool => 'review' === $t->getName(),
        ));
        self::assertCount(1, $review, 'existe una única transición "review"');
        self::assertSame(['submitted'], $review[0]->getFroms());
        self::assertSame(['in_review'], $review[0]->getTos());
    }
}
