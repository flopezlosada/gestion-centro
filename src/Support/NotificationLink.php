<?php

declare(strict_types=1);

namespace App\Support;

use App\Entity\Notification;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Where a notice takes you when you act on it. The SINGLE definition of that destination, shared by
 * the two places that need it: the Web Push payload ({@see \App\Service\NotificationDispatcher}) and
 * the inbox at /avisos (through {@see \App\Twig\NotificationExtension}). Kept apart so a push and the
 * inbox row for the same notice can never disagree — which is exactly what happened while the inbox
 * template only knew how to link task notices.
 *
 * Always a relative path, never an absolute URL: the batch that sends reminders runs from the CLI
 * (with no request to derive a host from), and the service worker resolves it against the app origin.
 */
final class NotificationLink
{
    public function __construct(private readonly UrlGeneratorInterface $urlGenerator)
    {
    }

    /**
     * The in-app path a notice should open.
     *
     * @param Notification $notification the notice
     *
     * @return string the path to open (e.g. "/tareas/42", "/" or "/avisos")
     */
    public function pathFor(Notification $notification): string
    {
        $task = $notification->getTask();
        if (null !== $task && null !== $task->getId()) {
            return $this->urlGenerator->generate('task_show', ['id' => $task->getId()]);
        }

        return match (true) {
            // A guardia notice (assigned/reassigned) opens the teacher's own "mis guardias", where they
            // see the guardia they were just assigned.
            str_starts_with($notification->getKind(), 'guardia.') => $this->urlGenerator->generate('guardia_mine'),
            // An unwatched recreo opens the gaps screen, which lists today's and the coming ones: that is
            // where the equipo directivo notes down whoever volunteers. The notice cannot carry the day
            // itself (only tasks are deep-linked), and that screen starts on the pending ones anyway.
            str_starts_with($notification->getKind(), 'break_duty.') => $this->urlGenerator->generate('break_duty_gap_index'),
            // An agenda reminder opens Inicio, which carries every event it can be about: it only fires
            // for a TIMED event (an all-day entry drops its reminder, see PersonalEvent::setAllDay) and
            // at most a day ahead (see EventReminderOffset), so the event is either in today's "Con
            // hora" block or in "Próximos 7 días".
            str_starts_with($notification->getKind(), 'event.') => $this->urlGenerator->generate('app_homepage'),
            // A meeting notice (convocatoria, cambio de hora, acta) opens "Mis reuniones", which carries
            // both what is coming and the archive of minutes. The notice cannot deep-link to the meeting
            // itself: a Notification only knows how to point at a Task, and widening that schema for
            // every module would mean a column per feature.
            str_starts_with($notification->getKind(), 'meeting.') => $this->urlGenerator->generate('meeting_index'),
            // Anything else has nowhere better to go than the inbox itself.
            default => $this->urlGenerator->generate('notification_index'),
        };
    }
}
