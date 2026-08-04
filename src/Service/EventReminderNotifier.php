<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Notification;
use App\Entity\PersonalEvent;
use App\Repository\PersonalEventRepository;

/**
 * Pushes the personal agenda reminders that have come due ("tu evento empieza en 10 minutos"). Meant
 * to run every few minutes (see {@see \App\Command\SendEventRemindersCommand}), unlike the daily
 * {@see TaskReminderNotifier}: the whole point is minute-level antelación.
 *
 * The delivery is push + in-app notice, never e-mail — the policy lives in
 * {@see NotificationDispatcher::wantsEmail()}, keyed by the "event." kind.
 *
 * ### On the sweep instant and time zones
 * The reminder compares an instant against `personal_event.remind_at`, and Doctrine stores/reads a
 * `datetime_immutable` in PHP's default time zone WITHOUT converting it. So the caller must pass a
 * "now" in that same default zone — which is what {@see \App\Command\SendEventRemindersCommand} does.
 * Handing over a zone of your own here would be a bug, not a fix: a day-matching sweep can get away
 * with it (the zone cancels out at midnight), but this one compares clock times, where a mismatched
 * zone shifts every reminder by the offset. There is nothing to force anyway — PHP's default zone IS
 * the centre's, anchored at boot by {@see \App\Kernel} instead of being left to the deployment.
 */
final class EventReminderNotifier
{
    public function __construct(
        private readonly PersonalEventRepository $events,
        private readonly NotificationDispatcher $dispatcher,
    ) {
    }

    /**
     * Notifies the owner of every entry whose reminder is due and not yet sent, and marks it as sent.
     *
     * The mark rides on the dispatcher's single flush, which happens BEFORE the push leaves: a push
     * that fails is not retried on the next run. That is deliberate — a reminder is only useful at its
     * moment, and retrying would rather risk pushing the same one repeatedly. The in-app notice is
     * persisted either way, so nothing is silently lost.
     *
     * @param \DateTimeImmutable $now the sweep instant, in PHP's default time zone (see the class doc)
     *
     * @return int the number of reminders sent
     */
    public function sendDue(\DateTimeImmutable $now): int
    {
        /** @var list<Notification> $notifications */
        $notifications = [];

        foreach ($this->events->findDueReminders($now) as $event) {
            $notifications[] = $this->dispatcher->record(
                $event->getOwner(),
                'event.reminder',
                $event->getTitle(),
                $this->bodyFor($event, $now),
            );
            $event->markReminderSent($now);
        }

        $this->dispatcher->flushAndSend($notifications);

        return \count($notifications);
    }

    /**
     * The one-line body of the reminder: when the entry starts, phrased relative to the sweep day so a
     * glance at the phone is enough ("Hoy a las 10:30").
     *
     * @param PersonalEvent      $event the entry being reminded about
     * @param \DateTimeImmutable $now   the sweep instant
     *
     * @return string the body text
     */
    private function bodyFor(PersonalEvent $event, \DateTimeImmutable $now): string
    {
        $start = $event->getStartAt();
        $day = match ($start->format('Y-m-d')) {
            $now->format('Y-m-d') => 'Hoy',
            $now->modify('+1 day')->format('Y-m-d') => 'Mañana',
            default => 'El '.$start->format('d/m'),
        };

        return \sprintf('%s a las %s.', $day, $start->format('H:i'));
    }
}
