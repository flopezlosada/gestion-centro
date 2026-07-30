<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Notification;
use App\Entity\Task;
use App\Entity\User;
use App\Security\AccessGate;
use App\Support\NotificationLink;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * The single place that materialises "notify a person": it persists the in-app notice and then sends
 * it as a Web Push notification and, for the kinds that warrant one ({@see wantsEmail()}), an e-mail.
 * Every notifier ({@see GuardiaAssignmentNotifier}, {@see TaskAssignmentNotifier},
 * {@see TaskReminderNotifier}, {@see EventReminderNotifier}) decides WHO to notify and WHAT to say and
 * delegates the delivery here, so the delivery channels live in one spot.
 *
 * Both the e-mail and the push legs are best-effort: a failure on either is logged and swallowed so it
 * never loses the in-app notice (already persisted) nor aborts a nightly batch.
 *
 * Nothing is delivered to someone who cannot get into the application ({@see AccessGate}): during a
 * phased roll-out, mailing "tienes una tarea, entra" to staff who cannot sign in yet only confuses
 * them. The in-app notice IS still written, on purpose — see {@see flushAndSend()}.
 *
 * Two entry points to fit both callers:
 *  - {@see dispatch()} for a single notice (persist + flush + send in one call);
 *  - {@see record()} + {@see flushAndSend()} for a batch (record many, one flush, then send), which is
 *    what the reminder engine needs to avoid a flush per notice.
 */
final class NotificationDispatcher
{
    /**
     * Kinds (by prefix) delivered by push and the in-app bell only, never by e-mail: the reminders that
     * fire at the very moment they are about. See {@see wantsEmail()}.
     */
    private const array PUSH_ONLY_KINDS = ['event.', 'guardia.raices'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface $mailer,
        private readonly WebPushSender $webPush,
        private readonly AccessGate $accessGate,
        private readonly NotificationLink $link,
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
     * Flushes any pending in-app notices, then delivers each over Web Push and — when the kind calls
     * for it ({@see wantsEmail()}) — e-mail. A failure on a single recipient/channel is logged and
     * skipped so it never aborts the rest of a batch.
     *
     * Recipients who cannot sign in are written but not delivered to. The notice is deliberately
     * still persisted: the reminder engine is idempotent by matching an exact day, with no
     * "already notified" flag, so skipping the write would lose that reminder for good instead of
     * merely postponing it — the person finds it waiting the day their access opens.
     *
     * @param iterable<Notification> $notifications the notices to deliver (already recorded)
     */
    public function flushAndSend(iterable $notifications): void
    {
        $this->entityManager->flush();

        foreach ($notifications as $notification) {
            $recipient = $notification->getRecipient();
            // The access gate comes first, before any channel: someone who cannot sign in gets nothing
            // delivered, whatever the kind would otherwise warrant.
            if (!$this->accessGate->allows($recipient)) {
                $this->logger->debug('Aviso guardado sin enviar: el destinatario no puede acceder', [
                    'recipient' => $recipient->getEmail(),
                    'kind' => $notification->getKind(),
                ]);

                continue;
            }

            if ($this->wantsEmail($notification)) {
                $this->email($notification);
            }

            $this->webPush->sendToUser(
                $recipient,
                $notification->getTitle(),
                $notification->getBody(),
                $this->link->pathFor($notification),
            );
        }
    }

    /**
     * Whether a notice also deserves an e-mail. Decided here, from the kind, for the same reason
     * {@see NotificationLink} decides the destination from it: it is a property of the KIND of notice,
     * not of whoever raises it, so every caller gets the same policy without passing a flag around.
     *
     * The exceptions are the reminders that fire AT the moment they are about: a personal agenda nudge
     * ("empieza en 10 minutos") and the RAICES reminder sent while a guardia is under way. By the time
     * an e-mail about either is read the moment has passed, so it would be pure noise in the inbox —
     * whereas "te han asignado una guardia" ({@see GuardiaAssignmentNotifier}) is about something days
     * ahead and does warrant one.
     *
     * @param Notification $notification the notice about to be delivered
     *
     * @return bool true when it should also go out by e-mail
     */
    private function wantsEmail(Notification $notification): bool
    {
        foreach (self::PUSH_ONLY_KINDS as $prefix) {
            if (str_starts_with($notification->getKind(), $prefix)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Sends one notice by e-mail. Best-effort: a transport failure is logged and swallowed so it never
     * costs the already-persisted in-app notice nor aborts the rest of a batch.
     *
     * @param Notification $notification the notice to send
     */
    private function email(Notification $notification): void
    {
        $recipient = $notification->getRecipient();

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
    }
}
