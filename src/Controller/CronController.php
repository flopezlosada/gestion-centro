<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\EventReminderNotifier;
use App\Service\MeetingReminderNotifier;
use App\Service\TaskReminderNotifier;
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
     * Daily: reminders for tasks about to be due and escalations for the overdue ones.
     */
    #[Route('/cron/task-reminders', name: 'cron_task_reminders', methods: ['GET'])]
    public function taskReminders(Request $request, TaskReminderNotifier $notifier): Response
    {
        $this->denyUnlessCronToken($request);

        // Reference DAY in the centre's timezone: this sweep matches whole days, so anchoring it to
        // Madrid keeps "today" from drifting to UTC near midnight.
        $count = $notifier->sendDue(new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid')));

        return new Response(\sprintf('%d avisos enviados.', $count));
    }

    /**
     * Every few minutes: push reminders for what is about to start — personal agenda events and convened
     * meetings. The route keeps its name so an already-configured cron does not have to be touched.
     */
    #[Route('/cron/event-reminders', name: 'cron_event_reminders', methods: ['GET'])]
    public function eventReminders(Request $request, EventReminderNotifier $events, MeetingReminderNotifier $meetings): Response
    {
        $this->denyUnlessCronToken($request);

        // PHP's default time zone, unlike the daily job above: these sweeps compare clock times against
        // the instants Doctrine wrote (see EventReminderNotifier's class doc).
        $now = new \DateTimeImmutable('now');
        $count = $events->sendDue($now) + $meetings->sendDue($now);

        return new Response(\sprintf('%d avisos de agenda enviados.', $count));
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
