<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Sends browser Web Push notifications (VAPID) to a user's registered devices. Thin wrapper over
 * {@see WebPush}: it fans a message out to every subscription the user holds and, crucially, prunes
 * the ones the push service reports as gone (404/410) so dead devices don't accumulate.
 *
 * Best-effort by design, like the e-mail leg of the notifiers: any failure (unconfigured VAPID keys,
 * network error, encryption error) is logged and swallowed so it never breaks the operation that
 * triggered the notice — the in-app notice is already persisted. When VAPID keys are absent (the
 * default in local/dev) it is a silent no-op.
 */
final class WebPushSender
{
    public function __construct(
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(VAPID_PUBLIC_KEY)%')]
        private readonly string $vapidPublicKey,
        #[Autowire('%env(VAPID_PRIVATE_KEY)%')]
        private readonly string $vapidPrivateKey,
        #[Autowire('%env(VAPID_SUBJECT)%')]
        private readonly string $vapidSubject,
    ) {
    }

    /**
     * Pushes a notification to every browser the user has subscribed. No-op if push is not
     * configured or the user has no subscriptions. Subscriptions the push service reports as
     * expired/gone are deleted.
     *
     * @param User        $user  the recipient
     * @param string      $title the notification title
     * @param string|null $body  the notification body
     * @param string      $path  the in-app path to open on click (e.g. "/tareas/42")
     */
    public function sendToUser(User $user, string $title, ?string $body, string $path): void
    {
        if ('' === $this->vapidPublicKey || '' === $this->vapidPrivateKey) {
            // Push not configured (the default in local/dev): nothing to do.
            return;
        }

        $subscriptions = $this->subscriptions->findByUser($user);
        if ([] === $subscriptions) {
            return;
        }

        $payload = json_encode([
            'title' => $title,
            'body' => $body ?? '',
            'url' => $path,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        try {
            $webPush = new WebPush(
                [
                    'VAPID' => [
                        'subject' => $this->vapidSubject,
                        'publicKey' => $this->vapidPublicKey,
                        'privateKey' => $this->vapidPrivateKey,
                    ],
                ],
                // Default options for every push: HIGH urgency so the push service (FCM/APNs) delivers it
                // immediately instead of batching it in the phone's low-power/Doze window — a guardia
                // alert is time-critical. TTL bounds how long it is kept if the device is offline (3 days,
                // long enough to reach a phone that was briefly off, short enough not to pop a stale
                // alert the next day).
                ['urgency' => 'high', 'TTL' => 259200],
                // A short timeout so one slow/hung push service cannot stall the whole reminder batch;
                // no redirects so a stored endpoint can never bounce the request to another host (SSRF).
                10,
                ['allow_redirects' => false],
            );

            // Endpoint is unique, so it maps a delivery report back to the row that must be pruned.
            $byEndpoint = [];
            foreach ($subscriptions as $subscription) {
                $byEndpoint[$subscription->getEndpoint()] = $subscription;
                $webPush->queueNotification(
                    new Subscription(
                        $subscription->getEndpoint(),
                        $subscription->getP256dh(),
                        $subscription->getAuth(),
                        'aes128gcm',
                    ),
                    $payload,
                );
            }

            $pruned = false;
            foreach ($webPush->flush() as $report) {
                if ($report->isSubscriptionExpired()) {
                    $expired = $byEndpoint[$report->getEndpoint()] ?? null;
                    if (null !== $expired) {
                        $this->entityManager->remove($expired);
                        $pruned = true;
                    }
                    continue;
                }

                if (!$report->isSuccess()) {
                    $this->logger->warning('Fallo al entregar una notificación push', [
                        'endpoint' => $report->getEndpoint(),
                        'reason' => $report->getReason(),
                    ]);
                }
            }

            if ($pruned) {
                $this->entityManager->flush();
            }
        } catch (\Throwable $e) {
            // Never let a push failure break the triggering operation (the in-app notice is saved).
            $this->logger->error('No se pudieron enviar las notificaciones push', [
                'recipient' => $user->getEmail(),
                'exception' => $e,
            ]);
        }
    }
}
