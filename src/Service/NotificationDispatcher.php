<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Notification;
use App\Entity\Task;
use App\Entity\User;
use App\Enum\NotificationChannel;
use App\Enum\NotificationTopic;
use App\Security\AccessGate;
use App\Support\NotificationLink;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * The single place that materialises "notify a person": it persists the in-app notice and then delivers
 * it through the channels that notice deserves for that recipient ({@see channelFor()}) — the phone
 * (Web Push), e-mail, or both. Every notifier ({@see GuardiaAssignmentNotifier}, {@see TaskAssignmentNotifier},
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
     * Kinds (by prefix) the app delivers by push and the in-app bell only, never by e-mail: the
     * reminders that fire at the very moment they are about. This is the DEFAULT, not the rule — a
     * person who explicitly asks for e-mail in that section gets it. See {@see channelFor()}.
     */
    // `meeting.minutes` está aquí a propósito y NO es un olvido: el acta se envía aparte, por correo y con
    // el PDF adjunto, a cada convocado ({@see \App\Service\MinutesMailer}). Si el AVISO de "ya hay acta"
    // también fuera por correo, cada persona recibiría dos mensajes de la misma acta y el segundo, sin
    // adjunto, sería el peor de los dos.
    private const array PUSH_ONLY_KINDS = ['event.', 'guardia.raices', 'meeting.reminder', 'meeting.minutes'];

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
     * Flushes any pending in-app notices, then delivers each through the channels the recipient wants
     * for that section ({@see channelFor()}). A failure on a single recipient/channel is logged and
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

            $channel = $this->channelFor($notification);

            if ($channel->sendsEmail()) {
                $this->email($notification);
            }

            if ($channel->sendsPush()) {
                $this->webPush->sendToUser(
                    $recipient,
                    $notification->getTitle(),
                    $notification->getBody(),
                    $this->link->pathFor($notification),
                );
            }
        }
    }

    /**
     * By which way to deliver a notice: what the recipient chose for that section of the app, and only
     * failing that the app's own default for that kind.
     *
     * The person's choice comes FIRST and wins outright ({@see \App\Entity\User::channelFor()}): the
     * centre's complaint was precisely that turning on the phone did not stop the e-mails, so a setting
     * the app can quietly overrule is not a setting. If somebody asks for their agenda nudges by e-mail
     * knowing they arrive as the event starts, that is their call to make.
     *
     * The default, for whoever has not chosen, is the policy this class always had: both channels,
     * except the reminders that fire AT the moment they are about — a personal agenda nudge ("empieza en
     * 10 minutos"), the RAICES reminder sent while a guardia is under way, and the one that says a
     * meeting is about to start. By the time an e-mail about any of those is read the moment has passed.
     *
     * A published acta is push-only for a different reason: {@see MinutesMailer} already sends it by
     * e-mail WITH THE PDF ATTACHED. Both would mean two messages to the same person about the same acta,
     * one of them worse than the other. A convocatoria and a change of time do go by e-mail — those you
     * want in writing and nobody else is sending them.
     *
     * @param Notification $notification the notice about to be delivered
     *
     * @return NotificationChannel the channel to deliver it through
     */
    private function channelFor(Notification $notification): NotificationChannel
    {
        $kind = $notification->getKind();
        $topic = NotificationTopic::fromKind($kind);
        $chosen = null !== $topic ? $notification->getRecipient()->channelFor($topic) : null;
        if (null !== $chosen) {
            return $chosen;
        }

        foreach (self::PUSH_ONLY_KINDS as $prefix) {
            if (str_starts_with($kind, $prefix)) {
                return NotificationChannel::PUSH;
            }
        }

        return NotificationChannel::BOTH;
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
