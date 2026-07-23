<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Carries a human reason ("motivo") from a controller into the {@see \App\EventSubscriber\EntityAuditSubscriber}
 * for the next flush, so a deliberate manual change (e.g. reassigning a guardia, flagging that it was
 * not covered) is recorded in the activity trail with WHY it was made, not just the field diff.
 *
 * Request-scoped by usage: a controller sets the reason right before the flush that changes the
 * entity, and the subscriber reads it once in postFlush and clears it, so it never leaks into an
 * unrelated later flush of the same request.
 */
final class AuditContext
{
    private ?string $reason = null;

    /**
     * Sets the reason to attach to the audit entries of the next flush (null clears it).
     *
     * @param string|null $reason the human motivo, or null
     */
    public function setReason(?string $reason): void
    {
        $this->reason = null !== $reason && '' !== trim($reason) ? trim($reason) : null;
    }

    /**
     * Reads and clears the pending reason, so it applies to a single flush only.
     *
     * @return string|null the pending motivo, or null
     */
    public function consumeReason(): ?string
    {
        $reason = $this->reason;
        $this->reason = null;

        return $reason;
    }
}
