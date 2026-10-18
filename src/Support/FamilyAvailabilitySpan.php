<?php

declare(strict_types=1);

namespace App\Support;

use App\Entity\FamilyAvailability;

/**
 * When some family can come, without saying which: what the staff's overview is made of. A type of its
 * own so the overview cannot reach the family behind a span, rather than relying on the template not to.
 */
final readonly class FamilyAvailabilitySpan
{
    /**
     * @param \DateTimeImmutable $from  from when (time of day)
     * @param \DateTimeImmutable $until until when (time of day)
     */
    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $until,
    ) {
    }

    /**
     * @param FamilyAvailability $availability a family's span
     *
     * @return self the same hours, without the family
     */
    public static function of(FamilyAvailability $availability): self
    {
        return new self($availability->getFrom(), $availability->getUntil());
    }
}
