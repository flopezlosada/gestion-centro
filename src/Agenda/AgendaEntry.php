<?php

declare(strict_types=1);

namespace App\Agenda;

use App\Entity\GuardiaCover;
use App\Entity\Meeting;
use App\Entity\PersonalEvent;
use App\Entity\Task;

/**
 * A single line of the personal agenda, wrapping an institutional {@see Task}, a private
 * {@see PersonalEvent}, a {@see GuardiaCover} or a convened {@see Meeting} behind a common sort/bucket key
 * ({@see $date}) and done flag. It deliberately does NOT flatten them into one shape — a task carries a
 * workflow and a role, an event does not, a guardia is imposed by the centre and cannot be ticked off, a
 * meeting has other people convened to it — so the template keeps rendering each with its own marker; this
 * only unifies the ordering and the "which time bucket" decision that the agenda needs across the four
 * kinds. The shapes match the markers the calendar already uses: círculo = tarea, cuadrado = evento,
 * escudo = guardia, personas = reunión.
 */
final readonly class AgendaEntry
{
    public const string KIND_TASK = 'task';
    public const string KIND_EVENT = 'event';
    public const string KIND_GUARDIA = 'guardia';
    public const string KIND_MEETING = 'meeting';

    private function __construct(
        // One of the self::KIND_* constants.
        public string $kind,
        // The instant this entry sorts and buckets by: a task's deadline, an event's start, a guardia's
        // period start or a meeting's start.
        public \DateTimeImmutable $date,
        public bool $done,
        public ?Task $task,
        public ?PersonalEvent $event,
        public ?GuardiaCover $guardia = null,
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
     * Wraps a guardia the viewer has been assigned, keyed by the instant its period starts so it
     * interleaves by clock with the rest of the day. The period times live in the course's timetable,
     * not in the cover, so the caller resolves them and passes the start time in; without an imported
     * timetable it is null and the entry falls back to the cover's day (midnight), which still buckets
     * and sorts correctly — it just cannot show an hour.
     *
     * `done` is always FALSE: a guardia is not a checklist line. Whether it is already over is a matter
     * of the clock, and the surface that needs that reading gets it from {@see \App\Guardia\TeacherGuardiaDay}
     * — putting it here would make it look tickable, which it is not.
     *
     * @param GuardiaCover            $cover    the cover assigned to the viewer
     * @param \DateTimeImmutable|null $startsAt the instant its period starts, or null when unknown
     *
     * @return self the agenda entry
     */
    public static function fromGuardia(GuardiaCover $cover, ?\DateTimeImmutable $startsAt): self
    {
        return new self(self::KIND_GUARDIA, $startsAt ?? $cover->getDate(), false, null, null, $cover);
    }

    /**
     * Wraps a convened meeting, keyed by when it starts. NEVER "done", for the same reason a guardia is
     * not: a meeting is not something you tick off — you either turn up or you do not — so it has no
     * checkbox anywhere in the agenda. It also never needs one: only meetings from today onwards reach the
     * agenda ({@see PersonalAgenda::bucketsFor()}), so a past meeting cannot linger as if it were pending;
     * its record (and its acta) lives in "Mis reuniones".
     *
     * @param Meeting $meeting the meeting to wrap
     *
     * @return self the agenda entry
     */
    public static function fromMeeting(Meeting $meeting): self
    {
        return new self(self::KIND_MEETING, $meeting->getStartAt(), false, null, null, null, $meeting);
    }
}
