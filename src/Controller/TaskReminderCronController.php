<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\TaskReminderNotifier;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * HTTP trigger for the daily reminders, for hosts whose only scheduler is an HTTP cron. Authenticated
 * by a shared secret token in constant time; fail-closed (an empty CRON_SECRET disables it). GET is
 * safe to call repeatedly because the notifier is idempotent per day.
 */
final class TaskReminderCronController extends AbstractController
{
    #[Route('/cron/task-reminders', name: 'cron_task_reminders', methods: ['GET'])]
    public function __invoke(
        Request $request,
        TaskReminderNotifier $notifier,
        #[Autowire('%env(CRON_SECRET)%')]
        string $cronSecret,
    ): Response {
        if ('' === $cronSecret || !hash_equals($cronSecret, (string) $request->query->get('token'))) {
            throw new AccessDeniedHttpException('Token de cron inválido.');
        }

        $count = $notifier->sendDue(new \DateTimeImmutable());

        return new Response(sprintf('%d avisos enviados.', $count));
    }
}
