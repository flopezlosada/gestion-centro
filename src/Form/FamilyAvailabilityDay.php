<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\FamilyAvailability;
use App\Enum\Weekday;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * One row of a family's availability form: a day of the school week and, if they can come that day,
 * from and until when. Both times empty means "that day, no".
 */
final class FamilyAvailabilityDay
{
    /** From when, or null if they cannot come that day. Only the hour and minute carry meaning. */
    public ?\DateTimeImmutable $from = null;

    /** Until when, or null if they cannot come that day. */
    public ?\DateTimeImmutable $until = null;

    /**
     * @param Weekday $weekday the day of the row, fixed: the form does not let it be changed
     */
    public function __construct(public readonly Weekday $weekday)
    {
    }

    /**
     * Prefills a row from what the family had saved for that day, if anything.
     *
     * @param Weekday                 $weekday the day of the row
     * @param FamilyAvailability|null $saved   what is stored for that day, or null
     *
     * @return self the row
     */
    public static function of(Weekday $weekday, ?FamilyAvailability $saved): self
    {
        $day = new self($weekday);
        $day->from = $saved?->getFrom();
        $day->until = $saved?->getUntil();

        return $day;
    }

    /**
     * @return bool whether the family can come that day: both times given
     */
    public function isAvailable(): bool
    {
        return null !== $this->from && null !== $this->until;
    }

    /**
     * Both times or neither, and the second after the first — the same rule {@see FamilyAvailability}
     * enforces, told here in words before it gets there.
     *
     * @param ExecutionContextInterface $context the validation context to attach violations to
     */
    #[Assert\Callback]
    public function validateSpan(ExecutionContextInterface $context): void
    {
        if (null === $this->from && null === $this->until) {
            return;
        }
        if (null === $this->from || null === $this->until) {
            $context->buildViolation('Pon las dos horas, o deja las dos vacías si ese día no podéis.')
                ->atPath(null === $this->from ? 'from' : 'until')
                ->addViolation();

            return;
        }
        if ($this->until->format('H:i') <= $this->from->format('H:i')) {
            $context->buildViolation('La hora de «hasta» tiene que ser posterior a la de «desde».')
                ->atPath('until')
                ->addViolation();
        }
    }
}
