<?php

declare(strict_types=1);

namespace App\Support;

use App\Entity\AuditLog;
use App\Entity\Task;
use App\Entity\TaskComment;
use App\Entity\User;

/**
 * Everything the task detail screen needs to decide WHAT to show, resolved once in PHP instead of
 * being pieced together in Twig (handoff catalogo/handoff_detalle_tarea).
 *
 * Two questions drive that screen and neither is a template's job:
 *
 * 1. **Whose move is it?** The same task looks different depending on your part in it: whoever holds it
 *    delivers, whoever supervises decides, whoever delegated it follows up. {@see $role} answers it
 *    once so the decision card is chosen in one place — read from the actions the workflow actually
 *    allows, never from the status alone, so the card can never offer what the server would refuse.
 * 2. **Where is the task?** {@see $milestones} is the lifecycle line: the last two things that
 *    happened, what is happening now, and what is still to come.
 *
 * It also pulls the three comments that stop being "conversation" and become CONTENT — the reason it
 * came back, the note it was delivered with, the closing remark — out of {@see $thread}, so the same
 * text is never printed twice on one screen.
 */
final readonly class TaskDetailView
{
    /** Whoever has to do the task now (or hand it in again after a return). */
    public const string ROLE_RESPONSIBLE = 'responsible';

    /** Whoever gives the verdict on what someone else delivered. */
    public const string ROLE_VALIDATOR = 'validator';

    /** Whoever created it and handed it down: follows up, does not execute. */
    public const string ROLE_DELEGATOR = 'delegator';

    /** Closed (finalizada/cancelada): read-only for everybody. */
    public const string ROLE_CLOSED = 'closed';

    /** Anybody else who may see the task but has nothing to do with it. */
    public const string ROLE_OBSERVER = 'observer';

    /** Workflow transitions that are a verdict on somebody else's work. */
    private const array VERDICT = ['validate', 'review'];

    /**
     * How many already-happened milestones the lifecycle line keeps. Two, and not the whole trail: a
     * task that went back and forth four times would bury "what happens next" under its own history,
     * and that history is what the audit log is for.
     */
    private const int PAST_MILESTONES = 2;

    /**
     * @param string                                                                                                     $role        which decision card to render ({@see self::ROLE_RESPONSIBLE} …)
     * @param list<array{key: string, label: string, at: ?\DateTimeImmutable, who: ?string, note: ?string, tone: string}> $milestones  the lifecycle line, oldest first
     * @param list<TaskComment>                                                                                          $thread      the conversation, minus the comments promoted to content
     * @param TaskComment|null                                                                                           $returnNote  why it came back, when it is out for correction
     * @param TaskComment|null                                                                                           $deliveryNote what was said when handing it in, while it awaits a verdict
     * @param TaskComment|null                                                                                           $closingNote  the closing remark, once it is finalizada
     * @param User|null                                                                                                  $validator   the immediate superior who will give the verdict
     * @param \DateTimeImmutable|null                                                                                     $submittedAt when it was last handed in
     * @param \DateTimeImmutable|null                                                                                     $closedAt    when it was finalizada/cancelada
     * @param \DateTimeImmutable|null                                                                                     $delegatedAt when it was handed down to its current doer
     * @param int                                                                                                        $overdueDays full days past the deadline (0 when in time or closed)
     */
    private function __construct(
        public string $role,
        public array $milestones,
        public array $thread,
        public ?TaskComment $returnNote,
        public ?TaskComment $deliveryNote,
        public ?TaskComment $closingNote,
        public ?User $validator,
        public ?\DateTimeImmutable $submittedAt,
        public ?\DateTimeImmutable $closedAt,
        public ?\DateTimeImmutable $delegatedAt,
        public int $overdueDays,
    ) {
    }

    /**
     * Builds the view for one reader of one task.
     *
     * @param Task               $task      the task on screen
     * @param User               $viewer    who is looking at it
     * @param list<string>       $actions   the workflow transitions this viewer may fire now
     * @param list<AuditLog>     $trail     the task's audit entries (any order; newest-first as stored)
     * @param list<TaskComment>  $comments  its comment thread, oldest first
     * @param User|null          $validator its immediate superior, or null when nobody outranks it
     * @param \DateTimeImmutable $today     the day to measure the deadline against
     */
    public static function of(
        Task $task,
        User $viewer,
        array $actions,
        array $trail,
        array $comments,
        ?User $validator,
        \DateTimeImmutable $today,
    ): self {
        $stamps = self::statusStamps($trail);
        $delegatedAt = self::delegationStamp($trail);
        $highlighted = self::highlight($task, $comments);

        return new self(
            role: self::role($task, $viewer, $actions),
            milestones: self::milestones($task, $comments, $stamps, $delegatedAt, $today),
            // Whatever was promoted to content is dropped from the thread: printed in both places, the
            // one up top reads as a different, earlier message than the one in the conversation.
            thread: array_values(array_filter($comments, static fn (TaskComment $c): bool => !\in_array($c, $highlighted, true))),
            returnNote: $highlighted['review'] ?? null,
            deliveryNote: $highlighted['submit'] ?? null,
            closingNote: $highlighted['validate'] ?? null,
            validator: $validator,
            submittedAt: $stamps[TaskStatus::SUBMITTED] ?? null,
            closedAt: $stamps[TaskStatus::VALIDATED] ?? $stamps[TaskStatus::CANCELLED] ?? null,
            delegatedAt: $delegatedAt,
            overdueDays: self::overdueDays($task, $today),
        );
    }

    /**
     * Which decision card this reader gets. Order matters and it is not the status order:
     *
     * - Closed wins over everything — nothing is anybody's move any more.
     * - Then whoever delegated it: they may well be able to close it themselves ("Dar por finalizada"),
     *   but their card is follow-up, not a verdict on a delivery that never happened.
     * - Then the doer (the workflow offers them "submit"), then the judge, then a bystander.
     *
     * @param list<string> $actions the transitions the viewer may fire
     */
    private static function role(Task $task, User $viewer, array $actions): string
    {
        return match (true) {
            $task->isClosed() => self::ROLE_CLOSED,
            null !== $task->getDelegatedTo() && $task->getAssignedUser() === $viewer => self::ROLE_DELEGATOR,
            \in_array('submit', $actions, true) => self::ROLE_RESPONSIBLE,
            [] !== array_intersect(self::VERDICT, $actions) => self::ROLE_VALIDATOR,
            default => self::ROLE_OBSERVER,
        };
    }

    /**
     * When each place was last entered, from the audit trail. The trail is the only source that knows:
     * a delivery may carry no comment, and the entity keeps no timestamps of its own.
     *
     * @param list<AuditLog> $trail the task's audit entries
     *
     * @return array<string, \DateTimeImmutable> place → when it was last entered
     */
    private static function statusStamps(array $trail): array
    {
        $stamps = [];
        foreach ($trail as $entry) {
            $status = $entry->getChanges()['status']['new'] ?? null;
            if (!\is_string($status)) {
                continue;
            }
            // Newest-first as stored: the first stamp seen for a place is the last time it was entered
            // (a task can be delivered, returned and delivered again).
            $stamps[$status] ??= $entry->getOccurredAt();
        }

        return $stamps;
    }

    /**
     * When the task was last handed down to somebody. Only a delegation counts, never its withdrawal
     * (a "delegatedTo" set back to null), which is why the new value is checked and not just the field.
     *
     * @param list<AuditLog> $trail the task's audit entries
     */
    private static function delegationStamp(array $trail): ?\DateTimeImmutable
    {
        foreach ($trail as $entry) {
            $delegated = $entry->getChanges()['delegatedTo']['new'] ?? null;
            if (null !== $delegated && '' !== $delegated) {
                return $entry->getOccurredAt();
            }
        }

        return null;
    }

    /**
     * The comments that are no longer conversation but the content of the current state: the reason it
     * came back while it is out for correction, the delivery note while it awaits a verdict, the
     * closing remark once it is closed. The LAST one of its kind, because only the current lap counts.
     *
     * @param list<TaskComment> $comments the thread, oldest first
     *
     * @return array<string, TaskComment> transition → the comment to promote
     */
    private static function highlight(Task $task, array $comments): array
    {
        $wanted = match ($task->getStatus()) {
            TaskStatus::IN_REVIEW => 'review',
            TaskStatus::SUBMITTED => 'submit',
            TaskStatus::VALIDATED => 'validate',
            default => null,
        };
        if (null === $wanted) {
            return [];
        }

        $found = null;
        foreach ($comments as $comment) {
            if ($wanted === $comment->getTransition()) {
                $found = $comment;
            }
        }

        return null === $found ? [] : [$wanted => $found];
    }

    /**
     * The lifecycle line: the last {@see self::PAST_MILESTONES} things that happened, then where the
     * task stands now, and — while it is still pending — the validation still to come.
     *
     * @param list<TaskComment>                 $comments the thread, oldest first
     * @param array<string, \DateTimeImmutable> $stamps   place → when it was last entered
     *
     * @return list<array{key: string, label: string, at: ?\DateTimeImmutable, who: ?string, note: ?string, tone: string}>
     */
    private static function milestones(Task $task, array $comments, array $stamps, ?\DateTimeImmutable $delegatedAt, \DateTimeImmutable $today): array
    {
        $past = [
            self::milestone('created', 'Creada', $task->getCreatedAt(), $task->getCreatedBy()?->getFullName()),
        ];
        if (null !== $task->getDelegatedTo()) {
            $past[] = self::milestone('delegated', sprintf('Delegada a %s', $task->getDelegatedTo()->getFullName()), $delegatedAt, null);
        }
        if (isset($stamps[TaskStatus::SUBMITTED])) {
            $past[] = self::milestone('submitted', 'Entregada', $stamps[TaskStatus::SUBMITTED], self::authorOf('submit', $comments));
        }
        if (isset($stamps[TaskStatus::IN_REVIEW])) {
            $past[] = self::milestone('returned', 'Devuelta', $stamps[TaskStatus::IN_REVIEW], self::authorOf('review', $comments), tone: 'returned');
        }

        return [...\array_slice($past, -self::PAST_MILESTONES), ...self::currentMilestones($task, $stamps, $comments, $today)];
    }

    /**
     * Where the task stands now (and, while it is pending, what is still to come).
     *
     * @param array<string, \DateTimeImmutable> $stamps   place → when it was last entered
     * @param list<TaskComment>                 $comments the thread, oldest first
     *
     * @return list<array{key: string, label: string, at: ?\DateTimeImmutable, who: ?string, note: ?string, tone: string}>
     */
    private static function currentMilestones(Task $task, array $stamps, array $comments, \DateTimeImmutable $today): array
    {
        $overdue = self::overdueDays($task, $today);
        // Same wording as the deadline cell: "venció hace N días" is the one thing a pending task has to
        // say out loud, and saying it here too is what makes the line answer "¿voy tarde?".
        $deadline = $overdue > 0
            ? sprintf('venció hace %d %s', $overdue, 1 === $overdue ? 'día' : 'días')
            : sprintf('vence el %s', $task->getDueDate()->format('d/m/Y'));

        return match ($task->getStatus()) {
            TaskStatus::PENDING => [
                self::milestone('current', null !== $task->getDelegatedTo() ? sprintf('Pendiente · la lleva %s', $task->getDelegatedTo()->getFullName()) : 'Pendiente de entrega', null, null, $deadline, 'current'),
                self::milestone('future', 'Validación', null, null, null, 'future'),
            ],
            TaskStatus::SUBMITTED => [
                self::milestone('current', 'Esperando validación', null, null, null, 'current'),
            ],
            TaskStatus::IN_REVIEW => [
                self::milestone('current', 'Pendiente de nueva entrega', null, null, $deadline, 'current'),
            ],
            TaskStatus::VALIDATED => [
                self::milestone('closed', 'Finalizada', $stamps[TaskStatus::VALIDATED] ?? null, self::authorOf('validate', $comments), null, 'closed'),
            ],
            TaskStatus::CANCELLED => [
                self::milestone('closed', 'Cancelada', $stamps[TaskStatus::CANCELLED] ?? null, null, null, 'returned'),
            ],
            default => [],
        };
    }

    /**
     * @return array{key: string, label: string, at: ?\DateTimeImmutable, who: ?string, note: ?string, tone: string}
     */
    private static function milestone(string $key, string $label, ?\DateTimeImmutable $at, ?string $who, ?string $note = null, string $tone = 'done'): array
    {
        return ['key' => $key, 'label' => $label, 'at' => $at, 'who' => $who, 'note' => $note, 'tone' => $tone];
    }

    /**
     * Who fired a transition, taken from the comment it came with. The audit trail stores an e-mail and
     * the entity stores nothing, so a milestone shows a NAME only when a person wrote one down — never
     * a guess about who must have done it.
     *
     * @param list<TaskComment> $comments the thread, oldest first
     */
    private static function authorOf(string $transition, array $comments): ?string
    {
        $name = null;
        foreach ($comments as $comment) {
            if ($transition === $comment->getTransition() && null !== $comment->getAuthor()) {
                $name = $comment->getAuthor()->getFullName();
            }
        }

        return $name;
    }

    /**
     * Full days past the deadline, 0 when the task is in time or already closed. Measured between DAYS
     * ({@see Task::isOverdueOn()}): the deadline is a date, and measuring it as an instant makes the
     * count depend on the server's timezone.
     */
    private static function overdueDays(Task $task, \DateTimeImmutable $today): int
    {
        if (!$task->isOverdueOn($today)) {
            return 0;
        }

        $due = \DateTimeImmutable::createFromFormat('!Y-m-d', $task->getDueDate()->format('Y-m-d'));
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $today->format('Y-m-d'));

        return false === $due || false === $day ? 0 : (int) $due->diff($day)->days;
    }
}
