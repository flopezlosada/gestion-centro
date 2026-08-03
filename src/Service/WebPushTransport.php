<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PushSubscription;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The real {@see PushTransport}: signs each message with the centre's VAPID keys and hands it to the
 * browser vendor's push service through {@see WebPush}.
 *
 * Deliberately thin — it has no policy, only the two translations that the rest of the application
 * should not have to know: our {@see PushSubscription} rows into the library's {@see Subscription}
 * value objects, and the library's {@see MessageSentReport} back into a {@see PushDeliveryReport}.
 * The one piece of judgement it does carry is that "gone" means the library's
 * {@see MessageSentReport::isSubscriptionExpired()} (HTTP 404 or 410 from the push service) and
 * nothing else, so a timeout or a 5xx never costs somebody their device.
 */
// Alias explícito y no por la magia de "interfaz con una sola implementación": el día que alguien añada
// un transporte de mentira para dev, el contenedor dejaría de saber a quién inyectar y el error saldría
// lejos de aquí.
#[AsAlias(PushTransport::class)]
final class WebPushTransport implements PushTransport
{
    /**
     * Seconds a push service keeps the message for a device that is offline. Three days: long enough
     * to reach a phone that was briefly off, short enough not to pop a stale alert days later.
     *
     * Public so the diagnostic command ({@see \App\Command\PushTestCommand}) sends with the SAME options
     * production does — si divergen, la herramienta de diagnóstico miente sobre lo que pasa de verdad.
     */
    public const int TTL_SECONDS = 259200;

    /** Seconds to wait on the push service, so one hung endpoint cannot stall a whole reminder batch. */
    public const int TIMEOUT_SECONDS = 10;

    public function __construct(
        #[Autowire('%env(VAPID_PUBLIC_KEY)%')]
        private readonly string $vapidPublicKey,
        #[Autowire('%env(VAPID_PRIVATE_KEY)%')]
        private readonly string $vapidPrivateKey,
        #[Autowire('%env(VAPID_SUBJECT)%')]
        private readonly string $vapidSubject,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->vapidPublicKey && '' !== $this->vapidPrivateKey;
    }

    public function send(array $subscriptions, string $payload): iterable
    {
        $webPush = new WebPush(
            [
                'VAPID' => [
                    'subject' => $this->vapidSubject,
                    'publicKey' => $this->vapidPublicKey,
                    'privateKey' => $this->vapidPrivateKey,
                ],
            ],
            // HIGH urgency so the push service delivers immediately instead of batching it into the
            // phone's low-power window — a guardia alert is time-critical.
            ['urgency' => 'high', 'TTL' => self::TTL_SECONDS],
            self::TIMEOUT_SECONDS,
            // No redirects: a stored endpoint can never bounce the request to another host (SSRF).
            ['allow_redirects' => false],
        );

        foreach ($subscriptions as $subscription) {
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

        foreach ($webPush->flush() as $report) {
            yield $this->toDeliveryReport($report);
        }
    }

    /**
     * Translates one library report into ours. Public so the 404/410 reading can be pinned by a test
     * against real {@see MessageSentReport} instances, which is where that rule actually lives.
     *
     * @param MessageSentReport $report the library's delivery report
     *
     * @return PushDeliveryReport the same outcome in the application's terms
     */
    public function toDeliveryReport(MessageSentReport $report): PushDeliveryReport
    {
        if ($report->isSubscriptionExpired()) {
            return PushDeliveryReport::gone($report->getEndpoint(), $report->getReason());
        }

        return $report->isSuccess()
            ? PushDeliveryReport::delivered($report->getEndpoint())
            : PushDeliveryReport::failed($report->getEndpoint(), $report->getReason());
    }
}
