<?php

declare(strict_types=1);

namespace App\Service;

/**
 * What happened when one push was delivered to one browser, in the application's own terms.
 *
 * It exists so {@see WebPushSender} — which decides what to DO with a delivery outcome (prune a dead
 * device, log a failure, swallow the rest) — never has to know the shape of the push library's report.
 * The two facts it needs are which endpoint the outcome is about and whether that subscription is
 * GONE, which is a very different thing from "the push failed": a transient network error must not
 * cost the user their subscription, while a 404/410 means the browser threw it away and the row is
 * now garbage.
 */
final readonly class PushDeliveryReport
{
    /**
     * @param string      $endpoint         the push endpoint the outcome is about (identifies the subscription)
     * @param bool        $delivered        whether the push service accepted the message
     * @param bool        $subscriptionGone whether the push service says this subscription no longer exists
     * @param string|null $reason           why it failed, when it did
     */
    public function __construct(
        public string $endpoint,
        public bool $delivered,
        public bool $subscriptionGone,
        public ?string $reason = null,
    ) {
    }

    /**
     * A delivery that went through.
     *
     * @param string $endpoint the push endpoint
     *
     * @return self the report
     */
    public static function delivered(string $endpoint): self
    {
        return new self($endpoint, true, false);
    }

    /**
     * A subscription the push service reports as gone (404/410): the device is dead and the row has to
     * be pruned, not retried.
     *
     * @param string      $endpoint the push endpoint
     * @param string|null $reason   the reported reason, if any
     *
     * @return self the report
     */
    public static function gone(string $endpoint, ?string $reason = null): self
    {
        return new self($endpoint, false, true, $reason);
    }

    /**
     * A delivery that failed for a reason that does NOT invalidate the subscription (timeout, 5xx,
     * encryption error): worth logging, never worth deleting the device over.
     *
     * @param string      $endpoint the push endpoint
     * @param string|null $reason   the reported reason, if any
     *
     * @return self the report
     */
    public static function failed(string $endpoint, ?string $reason = null): self
    {
        return new self($endpoint, false, false, $reason);
    }
}
