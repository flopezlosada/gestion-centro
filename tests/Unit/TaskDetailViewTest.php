<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\AuditLog;
use App\Entity\Task;
use App\Entity\TaskComment;
use App\Entity\User;
use App\Enum\TaskType;
use App\Support\TaskDetailView;
use App\Support\TaskStatus;
use PHPUnit\Framework\TestCase;

/**
 * What the task detail decides before rendering: whose move it is, where the task stands, and which
 * comments stop being conversation to become the content of the current state.
 *
 * The lifecycle line is built from the audit trail because nothing else knows when a place was entered
 * (the entity keeps no timestamps and a delivery may carry no comment), so the fixtures here look like
 * the real trail: newest-first, with the raw `status` diff.
 */
final class TaskDetailViewTest extends TestCase
{
    private function user(string $name): User
    {
        $user = new User();
        $user->setEmail(str_replace(' ', '.', mb_strtolower($name)).'@centro.test');

        return $user->setFullName($name);
    }

    private function task(string $status = TaskStatus::PENDING, string $due = '2026-06-30'): Task
    {
        $task = new Task('Memoria', '2025-2026', new \DateTimeImmutable($due), TaskType::WITH_DELIVERABLE);

        return $task->setStatus($status);
    }

    /**
     * An audit entry of a status change, as EntityAuditSubscriber writes it. Its `occurredAt` is fixed by
     * the constructor (the trail is append-only and has no setter), so the assertions compare against the
     * entry's own timestamp instead of a literal date.
     */
    private function statusEntry(string $to): AuditLog
    {
        return new AuditLog('task.updated', 'quien.sea@centro.test', 'Task', '1', null, ['status' => ['old' => 'pending', 'new' => $to]]);
    }

    public function testWhoeverIsOfferedSubmitIsTheOneWhoseMoveItIs(): void
    {
        $view = TaskDetailView::of($this->task(), $this->user('Jaime Martinez'), ['submit'], [], [], null, new \DateTimeImmutable('2026-01-01'));

        self::assertSame(TaskDetailView::ROLE_RESPONSIBLE, $view->role);
    }

    public function testWhoeverIsOfferedTheVerdictGetsTheDecisionCard(): void
    {
        $view = TaskDetailView::of($this->task(TaskStatus::SUBMITTED), $this->user('Natalia Rodriguez'), ['validate', 'review'], [], [], null, new \DateTimeImmutable('2026-01-01'));

        self::assertSame(TaskDetailView::ROLE_VALIDATOR, $view->role);
    }

    public function testTheTitularOfADelegatedTaskFollowsUpEvenThoughSheCouldCloseIt(): void
    {
        // El caso que decide el orden de la cascada: la directora puede "Dar por finalizada" (tiene
        // 'validate'), pero su tarjeta es de SEGUIMIENTO — no hay entrega que juzgar, la lleva otra
        // persona. Con el papel leído del estado, esta pantalla salía como "Tu decisión".
        $boss = $this->user('Natalia Rodriguez');
        $task = $this->task();
        $task->setAssignedUser($boss)->setDelegatedTo($this->user('Pedro Juez'));

        $view = TaskDetailView::of($task, $boss, ['validate', 'cancel'], [], [], null, new \DateTimeImmutable('2026-01-01'));

        self::assertSame(TaskDetailView::ROLE_DELEGATOR, $view->role);
    }

    public function testAClosedTaskIsReadOnlyForEverybody(): void
    {
        $view = TaskDetailView::of($this->task(TaskStatus::VALIDATED), $this->user('Jaime Martinez'), ['reopen'], [], [], null, new \DateTimeImmutable('2026-01-01'));

        self::assertSame(TaskDetailView::ROLE_CLOSED, $view->role);
    }

    public function testSomebodyWithNothingToDoGetsNoDecisionCard(): void
    {
        $view = TaskDetailView::of($this->task(), $this->user('Alguien Deppaso'), [], [], [], null, new \DateTimeImmutable('2026-01-01'));

        self::assertSame(TaskDetailView::ROLE_OBSERVER, $view->role);
    }

    public function testTheReasonItCameBackIsPulledOutOfTheThreadSoItIsNotPrintedTwice(): void
    {
        $task = $this->task(TaskStatus::IN_REVIEW);
        $doer = $this->user('Jaime Martinez');
        $boss = $this->user('Natalia Rodriguez');
        $delivery = new TaskComment($task, $doer, 'Va la parte de resultados.', 'submit');
        $reason = new TaskComment($task, $boss, 'Faltan los datos del tercer trimestre.', 'review');
        $loose = new TaskComment($task, $doer, '¿Vale con el guion de la memoria?');

        $view = TaskDetailView::of($task, $doer, ['submit'], [], [$delivery, $reason, $loose], null, new \DateTimeImmutable('2026-01-01'));

        self::assertSame($reason, $view->returnNote);
        // Lo destacado sale del hilo; lo demás sigue ahí, en orden.
        self::assertSame([$delivery, $loose], $view->thread);
        self::assertNull($view->deliveryNote, 'la nota de la entrega no es el contenido de una devuelta');
    }

    public function testOnlyTheLastReturnCountsWhenTheTaskWentBackTwice(): void
    {
        $task = $this->task(TaskStatus::IN_REVIEW);
        $boss = $this->user('Natalia Rodriguez');
        $first = new TaskComment($task, $boss, 'Falta el anexo.', 'review');
        $second = new TaskComment($task, $boss, 'Sigue faltando el anexo de 2.º B.', 'review');

        $view = TaskDetailView::of($task, $this->user('Jaime Martinez'), ['submit'], [], [$first, $second], null, new \DateTimeImmutable('2026-01-01'));

        self::assertSame($second, $view->returnNote, 'el motivo vigente es el de esta vuelta');
        self::assertSame([$first], $view->thread, 'la vuelta anterior se queda en la conversación');
    }

    public function testTheClosingRemarkIsTheContentOfAFinishedTask(): void
    {
        $task = $this->task(TaskStatus::VALIDATED);
        $boss = $this->user('Natalia Rodriguez');
        $closing = new TaskComment($task, $boss, 'Presentado en el claustro del 13.', 'validate');

        $view = TaskDetailView::of($task, $boss, [], [], [$closing], null, new \DateTimeImmutable('2026-01-01'));

        self::assertSame($closing, $view->closingNote);
        self::assertSame([], $view->thread);
    }

    public function testTheLifecycleLineKeepsTheLastTwoThingsThatHappenedAndWhereItStands(): void
    {
        $task = $this->task(TaskStatus::IN_REVIEW);
        // Newest-first, como los devuelve AuditLogRepository::findForSubject().
        $returned = $this->statusEntry(TaskStatus::IN_REVIEW);
        $trail = [$returned, $this->statusEntry(TaskStatus::SUBMITTED)];

        $view = TaskDetailView::of($task, $this->user('Jaime Martinez'), ['submit'], $trail, [], null, new \DateTimeImmutable('2026-01-11'));

        // Creada cae fuera: con dos hitos por delante, la línea responde "¿y ahora qué?" y no "¿de dónde
        // viene?" — eso es lo que cuenta el histórico.
        self::assertSame(['submitted', 'returned', 'current'], array_column($view->milestones, 'key'));
        self::assertSame(['done', 'returned', 'current'], array_column($view->milestones, 'tone'));
        self::assertSame('Pendiente de nueva entrega', $view->milestones[2]['label']);
        self::assertSame($returned->getOccurredAt(), $view->milestones[1]['at']);
    }

    public function testAPendingTaskAlsoShowsTheValidationStillToCome(): void
    {
        $view = TaskDetailView::of($this->task(), $this->user('Jaime Martinez'), ['submit'], [], [], null, new \DateTimeImmutable('2026-01-01'));

        self::assertSame(['created', 'current', 'future'], array_column($view->milestones, 'key'));
        self::assertSame('vence el 30/06/2026', $view->milestones[1]['note']);
    }

    public function testTheCurrentMilestoneSaysHowLateItIs(): void
    {
        $view = TaskDetailView::of($this->task(TaskStatus::PENDING, '2026-06-28'), $this->user('Jaime Martinez'), ['submit'], [], [], null, new \DateTimeImmutable('2026-06-30'));

        self::assertSame(2, $view->overdueDays);
        self::assertSame('venció hace 2 días', $view->milestones[1]['note']);
    }

    public function testADeliveryIsDatedFromTheTrailEvenWhenNobodyWroteANote(): void
    {
        $task = $this->task(TaskStatus::SUBMITTED);
        $delivered = $this->statusEntry(TaskStatus::SUBMITTED);

        $view = TaskDetailView::of($task, $this->user('Natalia Rodriguez'), ['validate'], [$delivered], [], null, new \DateTimeImmutable('2026-01-10'));

        self::assertSame($delivered->getOccurredAt(), $view->submittedAt);
        // Sin comentario no hay nombre: el rastro guarda un correo, y la línea no inventa quién lo hizo.
        self::assertNull($view->milestones[1]['who']);
    }

    public function testOnlyAHandoverCountsAsADelegationAndNotItsWithdrawal(): void
    {
        $task = $this->task();
        $task->setDelegatedTo($this->user('Pedro Juez'));
        // Newest-first: primero la retirada (que no cuenta), después la entrega de la tarea a otra persona.
        $withdrawal = new AuditLog('task.updated', null, 'Task', '1', null, ['delegatedTo' => ['old' => 7, 'new' => null]]);
        $handover = new AuditLog('task.updated', null, 'Task', '1', null, ['delegatedTo' => ['old' => null, 'new' => 7]]);

        $view = TaskDetailView::of($task, $this->user('Natalia Rodriguez'), [], [$withdrawal, $handover], [], null, new \DateTimeImmutable('2026-01-21'));

        self::assertSame($handover->getOccurredAt(), $view->delegatedAt);
    }

    public function testAClosedTaskIsNeverOverdue(): void
    {
        // Misma definición que la entidad ({@see Task::isOverdueOn()}): una tarea cerrada tarde no arrastra
        // el aviso de fuera de plazo el resto del curso.
        $view = TaskDetailView::of($this->task(TaskStatus::VALIDATED, '2025-09-01'), $this->user('Jaime Martinez'), [], [], [], null, new \DateTimeImmutable('2026-06-30'));

        self::assertSame(0, $view->overdueDays);
    }
}
