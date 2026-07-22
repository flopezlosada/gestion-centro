<?php

declare(strict_types=1);

namespace App\Agenda;

use App\Entity\PersonalEvent;
use App\Entity\Task;
use App\Support\TaskStatus;

/**
 * A single line of the personal agenda, wrapping either an institutional {@see Task} or a private
 * {@see PersonalEvent} behind a common sort/bucket key ({@see $date}) and done flag. It deliberately
 * does NOT flatten the two into one shape — a task carries a workflow and a role, an event does not —
 * so the template keeps rendering each with its own macro; this only unifies the ordering and the
 * "which time bucket" decision that the agenda needs across both kinds.
 */
final readonly class AgendaEntry
{
    public const string KIND_TASK = 'task';
    public const string KIND_EVENT = 'event';

    private function __construct(
        // self::KIND_TASK or self::KIND_EVENT.
        public string $kind,
        // The day this entry sorts and buckets by: a task's deadline or an event's start.
        public \DateTimeImmutable $date,
        public bool $done,
        public ?Task $task,
        public ?PersonalEvent $event,
    ) {
    }

    /**
     * Wraps an institutional task, keyed by its deadline. Cuenta como "hecha" (bucket Hechas, fuera de
     * los pendientes) si el asignado marcó su casilla de progreso o si la tarea ya está Finalizada
     * ({@see TaskStatus::VALIDATED}) — así una finalizada no vuelve a aparecer como pendiente. Las
     * canceladas ni llegan aquí: {@see TaskRepository::findAgendaFor()} las excluye.
     *
     * @param Task $task the task to wrap
     *
     * @return self the agenda entry
     */
    public static function fromTask(Task $task): self
    {
        $done = $task->isCheckboxDone() || TaskStatus::VALIDATED === $task->getStatus();

        return new self(self::KIND_TASK, $task->getDueDate(), $done, $task, null);
    }

    /**
     * Wraps a personal event, keyed by its start instant and its done flag.
     *
     * @param PersonalEvent $event the event to wrap
     *
     * @return self the agenda entry
     */
    public static function fromEvent(PersonalEvent $event): self
    {
        return new self(self::KIND_EVENT, $event->getStartAt(), $event->isDone(), null, $event);
    }
}
