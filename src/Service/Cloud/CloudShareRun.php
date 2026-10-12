<?php

declare(strict_types=1);

namespace App\Service\Cloud;

/**
 * What one pass over the queue of shares did ({@see CloudShareQueue::process()}).
 */
final readonly class CloudShareRun
{
    /**
     * @param int         $shared    shares made
     * @param int         $failed    shares given up on, each with its reason kept
     * @param string|null $stoppedBy why it stopped with shares still pending, or null when none is left
     * @param bool        $throttled whether what stopped it is the cloud's limit — the normal way for a long
     *                               queue to wait, not a failure
     */
    public function __construct(
        public int $shared,
        public int $failed,
        public ?string $stoppedBy,
        public bool $throttled = false,
    ) {
    }
}
