<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Meeting;
use App\Entity\Notification;
use App\Repository\MeetingRepository;

/**
 * Pushes the reminders of meetings about to start ("la reunión empieza en 10 minutos") to everybody
 * expected there — the convened AND whoever convened it, who also has to turn up.
 *
 * Runs on the same few-minutes sweep as the personal agenda reminders ({@see EventReminderNotifier}):
 * both carry a minute-level antelación, so they share the cron entry points
 * ({@see \App\Command\SendEventRemindersCommand}, {@see \App\Controller\CronController::eventReminders()})
 * instead of asking the deployment for a third scheduled job. Kept as its own class because the query and
 * the recipients are different: one notice per meeting fans out to N people.
 *
 * Push + in-app notice, never e-mail: by the time an e-mail is read the meeting has started (the policy
 * lives in {@see NotificationDispatcher::wantsEmail()}). The convocatoria itself DOES go by e-mail — that
 * one you want in writing.
 *
 * ### On the sweep instant and time zones
 * Same rule as {@see EventReminderNotifier}: `meeting.remind_at` is written and read by Doctrine in PHP's
 * default time zone without converting, so the caller must pass a "now" in that same zone — plain
 * `new \DateTimeImmutable('now')`. That zone is the centre's, anchored at boot by {@see \App\Kernel}; do
 * NOT hand over a zone of your own here, since any other one shifts every reminder by the offset.
 */
final class MeetingReminderNotifier
{
    public function __construct(
        private readonly MeetingRepository $meetings,
        private readonly NotificationDispatcher $dispatcher,
    ) {
    }

    /**
     * Notifies everybody expected at each meeting whose reminder is due and not yet sent, and marks it as
     * sent. The mark is per MEETING, not per person: the whole group is notified in this single pass, so
     * one flag makes the sweep idempotent.
     *
     * The mark rides on the dispatcher's single flush, which happens BEFORE the push leaves: a push that
     * fails is not retried on the next run, deliberately — a reminder is only useful at its moment, and
     * retrying would risk pushing the same one repeatedly. The in-app notice is persisted either way.
     *
     * @param \DateTimeImmutable $now the sweep instant, in PHP's default time zone (see the class doc)
     *
     * @return int the number of notices sent (people, not meetings)
     */
    public function sendDue(\DateTimeImmutable $now): int
    {
        /** @var list<Notification> $notifications */
        $notifications = [];

        foreach ($this->meetings->findDueReminders($now) as $meeting) {
            foreach ($meeting->people() as $person) {
                $notifications[] = $this->dispatcher->record(
                    $person,
                    'meeting.reminder',
                    \sprintf('Reunión: %s', $meeting->getTitle()),
                    $this->bodyFor($meeting, $now),
                );
            }
            $meeting->markReminderSent($now);
        }

        $this->dispatcher->flushAndSend($notifications);

        return \count($notifications);
    }

    /**
     * The one-line body: when it starts and where, phrased relative to the sweep day so a glance at the
     * phone is enough ("Hoy a las 14:00, en la sala de profesores.").
     *
     * @param Meeting            $meeting the meeting being reminded about
     * @param \DateTimeImmutable $now     the sweep instant
     *
     * @return string the body text
     */
    private function bodyFor(Meeting $meeting, \DateTimeImmutable $now): string
    {
        $start = $meeting->getStartAt();
        $day = match ($start->format('Y-m-d')) {
            $now->format('Y-m-d') => 'Hoy',
            $now->modify('+1 day')->format('Y-m-d') => 'Mañana',
            default => 'El '.$start->format('d/m'),
        };
        $when = \sprintf('%s a las %s', $day, $start->format('H:i'));

        return null !== $meeting->getPlace() ? $when.', en '.$meeting->getPlace().'.' : $when.'.';
    }
}
