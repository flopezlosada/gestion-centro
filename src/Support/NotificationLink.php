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
     * @return string the path to open (e.g. "/tareas/42", "/agenda" or "/avisos")
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
            // An agenda reminder opens the agenda, where the event it is about is the next one up.
            str_starts_with($notification->getKind(), 'event.') => $this->urlGenerator->generate('personal_event_index'),
            // Anything else has nowhere better to go than the inbox itself.
            default => $this->urlGenerator->generate('notification_index'),
        };
    }
}
