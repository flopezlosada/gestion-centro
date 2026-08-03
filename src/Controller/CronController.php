<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\DailyNotificationSweep;
use App\Service\EventReminderNotifier;
use App\Service\GuardiaRaicesReminder;
use App\Service\MeetingReminderNotifier;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * HTTP triggers for the scheduled jobs, for hosts whose only scheduler is an HTTP cron. Every route is
 * authenticated by the same shared secret in constant time and fails closed (an empty CRON_SECRET
 * disables them all). GET is safe to call repeatedly because both sweeps are idempotent.
 *
 * The jobs run at very different rates, hence two endpoints: task reminders once a day, and the
 * minute-level ones every five minutes. The second endpoint sweeps BOTH the personal agenda and the
 * convened meetings — they share the same antelación problem, and asking the deployment for a third cron
 * entry (one more thing to forget when moving hosts) buys nothing.
 */
final class CronController extends AbstractController
{
    public function __construct(
        #[Autowire('%env(CRON_SECRET)%')]
        private readonly string $cronSecret,
    ) {
    }

    /**
     * Daily: reminders for tasks about to be due, escalations for the overdue ones, and the purge of
     * the notices that have expired. Shares {@see DailyNotificationSweep} with the CLI command so the
     * two ways of running the same daily job can never drift apart.
     */
    #[Route('/cron/task-reminders', name: 'cron_task_reminders', methods: ['GET'])]
    public function taskReminders(Request $request, DailyNotificationSweep $sweep): Response
    {
        $this->denyUnlessCronToken($request);

        // Reference DAY in the centre's timezone: this sweep matches whole days, so anchoring it to
        // Madrid keeps "today" from drifting to UTC near midnight.
        $result = $sweep->run(new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid')));

        return new Response(\sprintf('%d avisos enviados, %d caducados retirados.', $result['sent'], $result['purged']));
    }

    /**
     * Every few minutes: the three sweeps that carry a minute-level antelación — push reminders for
     * personal agenda events about to start, the "apunta las ausencias en RAICES" reminder for the guardias
     * being covered right now, and the reminder for a meeting about to begin.
     *
     * They share ONE endpoint on purpose. All of them want the same cadence, and splitting them would make
     * each new one depend on somebody remembering to add another entry to the host's cron table — a silent
     * "no reminders ever" if they do not. One URL, one schedule, every sweep.
     *
     * They also run unguarded, so a failure in the first stops the rest this tick. That is deliberate:
     * catching would turn a persistent breakage into a 200 with a half-done sweep, which no cron monitor
     * would flag, and every sweep is already best-effort per recipient ({@see NotificationDispatcher})
     * and retried five minutes later. Better all fail loudly together than one fail quietly alone.
     */
    #[Route('/cron/event-reminders', name: 'cron_event_reminders', methods: ['GET'])]
    public function eventReminders(Request $request, EventReminderNotifier $notifier, GuardiaRaicesReminder $raices, MeetingReminderNotifier $meetings): Response
    {
        $this->denyUnlessCronToken($request);

        // PHP's default time zone, unlike the daily job above: these sweeps compare clock times against
        // the instants Doctrine wrote / the timetable's period times (see each notifier's class doc).
        $now = new \DateTimeImmutable('now');
        $events = $notifier->sendDue($now);
        $guardias = $raices->sendDue($now);
        $meetingCount = $meetings->sendDue($now);

        return new Response(\sprintf('%d avisos de agenda, %d de RAICES y %d de reuniones enviados.', $events, $guardias, $meetingCount));
    }

    /**
     * Rejects the request unless it carries the shared cron secret. Compared in constant time, and
     * fail-closed: with no CRON_SECRET configured, nothing is callable.
     *
     * @param Request $request the incoming cron request
     */
    private function denyUnlessCronToken(Request $request): void
    {
        if ('' === $this->cronSecret || !hash_equals($this->cronSecret, (string) $request->query->get('token'))) {
            throw new AccessDeniedHttpException('Token de cron inválido.');
        }
    }
}
