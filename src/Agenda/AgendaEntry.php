<?php

declare(strict_types=1);

namespace App\Agenda;

use App\Entity\Meeting;
use App\Entity\PersonalEvent;
use App\Entity\Task;

/**
 * A single line of the personal agenda, wrapping an institutional {@see Task}, a private
 * {@see PersonalEvent} or a convened {@see Meeting} behind a common sort/bucket key ({@see $date}) and
 * done flag. It deliberately does NOT flatten them into one shape — a task carries a workflow and a
 * role, an event does not, a meeting has people convened — so the template keeps rendering each with its
 * own macro; this only unifies the ordering and the "which time bucket" decision that the agenda needs
 * across the three kinds.
 */
final readonly class AgendaEntry
{
    public const string KIND_TASK = 'task';
    public const string KIND_EVENT = 'event';
    public const string KIND_MEETING = 'meeting';

    private function __construct(
        // self::KIND_TASK, self::KIND_EVENT or self::KIND_MEETING.
        public string $kind,
        // The day this entry sorts and buckets by: a task's deadline, or an event's/meeting's start.
        public \DateTimeImmutable $date,
        public bool $done,
        public ?Task $task,
        public ?PersonalEvent $event,
        public ?Meeting $meeting = null,
    ) {
    }

    /**
     * Wraps an institutional task, keyed by its deadline. Cuenta como "hecha" (bucket `done`, aparte de
     * los pendientes, que es lo único que pinta Inicio) según {@see Task::isDone()} — casilla de
     * progreso marcada o tarea ya Finalizada, así una finalizada no vuelve a aparecer como pendiente.
     * La vista de día del calendario pinta su casilla con ese MISMO flag, para que "cuenta como hecha" y
     * "se ve hecha" no puedan discrepar. Las canceladas ni llegan aquí:
     * {@see \App\Repository\TaskRepository::findAgendaFor()} las excluye.
     *
     * @param Task $task the task to wrap
     *
     * @return self the agenda entry
     */
    public static function fromTask(Task $task): self
    {
        return new self(self::KIND_TASK, $task->getDueDate(), $task->isDone(), $task, null);
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

    /**
     * Wraps a convened meeting, keyed by when it starts. NEVER "done": a meeting is not something you
     * tick off — you either turn up or you do not — so it has no checkbox anywhere in the agenda. It also
     * never needs one: only meetings from today onwards reach the agenda ({@see
     * \App\Agenda\PersonalAgenda::bucketsFor()}), so a past meeting cannot linger as if it were pending;
     * its record (and its acta) lives in "Mis reuniones".
     *
     * @param Meeting $meeting the meeting to wrap
     *
     * @return self the agenda entry
     */
    public static function fromMeeting(Meeting $meeting): self
    {
        return new self(self::KIND_MEETING, $meeting->getStartAt(), false, null, null, $meeting);
    }
}
