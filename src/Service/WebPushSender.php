<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends browser Web Push notifications to a user's registered devices and keeps that list honest:
 * it fans a message out to every subscription the user holds and prunes the ones the push service
 * reports as GONE (404/410), so dead devices don't accumulate for ever.
 *
 * Best-effort by design, like the e-mail leg of the notifiers: any failure (unconfigured VAPID keys,
 * network error, encryption error) is logged and swallowed so it never breaks the operation that
 * triggered the notice — the in-app notice is already persisted. When push is not configured (the
 * default in local/dev) it is a silent no-op.
 *
 * The wire itself lives behind {@see PushTransport}; this class only holds the policy, which is why
 * the pruning can be tested without a browser or a network.
 */
final class WebPushSender
{
    public function __construct(
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly PushTransport $transport,
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
        if (!$this->transport->isConfigured()) {
            return;
        }

        $subscriptions = $this->subscriptions->findByUser($user);
        if ([] === $subscriptions) {
            return;
        }

        try {
            $payload = json_encode([
                'title' => $title,
                'body' => $body ?? '',
                'url' => $path,
            ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

            // Endpoint is unique, so it maps a delivery report back to the row that must be pruned.
            $byEndpoint = [];
            foreach ($subscriptions as $subscription) {
                $byEndpoint[$subscription->getEndpoint()] = $subscription;
            }

            $pruned = false;
            foreach ($this->transport->send($subscriptions, $payload) as $report) {
                if ($report->subscriptionGone) {
                    $expired = $byEndpoint[$report->endpoint] ?? null;
                    if (null !== $expired) {
                        $this->entityManager->remove($expired);
                        $pruned = true;
                    }
                    continue;
                }

                if (!$report->delivered) {
                    $this->logger->warning('Fallo al entregar una notificación push', [
                        'endpoint' => $report->endpoint,
                        'reason' => $report->reason,
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
