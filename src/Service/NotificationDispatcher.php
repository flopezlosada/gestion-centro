<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Notification;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The single place that materialises "notify a person": it persists the in-app notice and then, for
 * each notice, sends an e-mail and a Web Push notification. Every notifier ({@see GuardiaAssignmentNotifier},
 * {@see TaskAssignmentNotifier}, {@see TaskReminderNotifier}) decides WHO to notify and WHAT to say
 * and delegates the delivery here, so the three delivery channels live in one spot.
 *
 * Both the e-mail and the push legs are best-effort: a failure on either is logged and swallowed so it
 * never loses the in-app notice (already persisted) nor aborts a nightly batch.
 *
 * Two entry points to fit both callers:
 *  - {@see dispatch()} for a single notice (persist + flush + send in one call);
 *  - {@see record()} + {@see flushAndSend()} for a batch (record many, one flush, then send), which is
 *    what the reminder engine needs to avoid a flush per notice.
 */
final class NotificationDispatcher
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface $mailer,
        private readonly WebPushSender $webPush,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.mailer_from%')]
        private readonly string $mailerFrom,
    ) {
    }

    /**
     * Persists a single in-app notice, flushes it and sends it over e-mail + push. Convenience for the
     * one-off callers (a guardia/task just assigned).
     *
     * @param User        $recipient who to notify
     * @param string      $kind      the machine kind (e.g. "guardia.assigned")
     * @param string      $title     the notice title
     * @param string|null $body      the notice body
     * @param Task|null   $task      the task it is about, for deep-linking
     *
     * @return Notification the persisted notice
     */
    public function dispatch(User $recipient, string $kind, string $title, ?string $body = null, ?Task $task = null): Notification
    {
        $notification = $this->record($recipient, $kind, $title, $body, $task);
        $this->flushAndSend([$notification]);

        return $notification;
    }

    /**
     * Builds and persists an in-app notice WITHOUT flushing or sending. Accumulate several then hand
     * them to {@see flushAndSend()} to deliver the whole batch with a single flush.
     *
     * @param User        $recipient who to notify
     * @param string      $kind      the machine kind
     * @param string      $title     the notice title
     * @param string|null $body      the notice body
     * @param Task|null   $task      the task it is about, for deep-linking
     *
     * @return Notification the persisted (not yet flushed) notice
     */
    public function record(User $recipient, string $kind, string $title, ?string $body = null, ?Task $task = null): Notification
    {
        $notification = new Notification($recipient, $kind, $title, $body, $task);
        $this->entityManager->persist($notification);

        return $notification;
    }

    /**
     * Flushes any pending in-app notices, then delivers each over e-mail and Web Push. A failure on a
     * single recipient/channel is logged and skipped so it never aborts the rest of a batch.
     *
     * @param iterable<Notification> $notifications the notices to deliver (already recorded)
     */
    public function flushAndSend(iterable $notifications): void
    {
        $this->entityManager->flush();

        foreach ($notifications as $notification) {
            $recipient = $notification->getRecipient();
            $path = $this->pathFor($notification);

            try {
                $this->mailer->send((new Email())
                    ->from($this->mailerFrom)
                    ->to($recipient->getEmail())
                    ->subject($notification->getTitle())
                    ->text((string) $notification->getBody()));
            } catch (TransportExceptionInterface $e) {
                $this->logger->error('No se pudo enviar el aviso por email', [
                    'recipient' => $recipient->getEmail(),
                    'exception' => $e,
                ]);
            }

            $this->webPush->sendToUser($recipient, $notification->getTitle(), $notification->getBody(), $path);
        }
    }

    /**
     * The in-app path a notice should open on click: the linked task, or the inbox otherwise. Kept as
     * a relative path (not absolute) so it works both from an HTTP request and from the CLI reminder
     * batch, and resolves against the app origin in the service worker.
     *
     * @param Notification $notification the notice
     *
     * @return string the path to open (e.g. "/tareas/42" or "/avisos")
     */
    private function pathFor(Notification $notification): string
    {
        $task = $notification->getTask();
        if (null !== $task && null !== $task->getId()) {
            return $this->urlGenerator->generate('task_show', ['id' => $task->getId()]);
        }

        return $this->urlGenerator->generate('notification_index');
    }
}
