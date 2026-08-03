<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PushSubscription;

/**
 * The wire that carries a push message to a browser: the one part of Web Push that talks to Google's,
 * Mozilla's or Apple's push service and therefore cannot run in a test.
 *
 * Everything above it — who gets the notice, which of their devices are dead, what to log and what to
 * swallow — is {@see WebPushSender}'s job and is plain logic. Splitting them apart is what makes the
 * 404/410 pruning testable at all: the fan-out and the pruning decision are exercised against a fake
 * transport, and the real one ({@see WebPushTransport}) stays a thin adapter with nothing to decide.
 */
interface PushTransport
{
    /**
     * Whether push is configured at all (VAPID keys present). False in local/dev by default, and the
     * reason sending is a silent no-op there rather than an error.
     *
     * @return bool true when a push can actually be signed and sent
     */
    public function isConfigured(): bool;

    /**
     * Delivers the same payload to each of the given subscriptions and reports what happened to each.
     *
     * @param list<PushSubscription> $subscriptions the devices to push to
     * @param string                 $payload       the JSON payload the service worker will receive
     *
     * @return iterable<PushDeliveryReport> one report per subscription, in no guaranteed order
     *
     * @throws \Throwable when the transport itself fails (bad keys, network); the caller swallows it
     */
    public function send(array $subscriptions, string $payload): iterable;
}
