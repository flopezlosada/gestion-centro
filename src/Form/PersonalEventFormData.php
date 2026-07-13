<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\EventCategory;
use App\Entity\PersonalEvent;
use App\Enum\RecurrenceFrequency;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Form-backing object for a {@see PersonalEvent}. A DTO on purpose: the entity stores start/end as
 * instants, but the form is friendlier split into a day plus "from"/"until" times (as "HH:MM"
 * strings), which the controller composes back into instants. The owner is set by the controller,
 * never by the form.
 */
final class PersonalEventFormData
{
    #[Assert\NotBlank(message: 'El título es obligatorio.')]
    #[Assert\Length(max: 200)]
    public string $title = '';

    public ?string $description = null;

    #[Assert\NotNull(message: 'Pon el día.')]
    public ?\DateTimeImmutable $day = null;

    /** Colour-coding category, or null for uncategorised. */
    public ?EventCategory $category = null;

    /** Start time as "HH:MM", or null for a no-time reminder (e.g. "llamar a Pepito"). */
    public ?string $startTime = null;

    /** End time as "HH:MM", or null when there is no explicit end (only valid with a start time). */
    public ?string $endTime = null;

    /** Recurrence frequency. */
    public RecurrenceFrequency $repeat = RecurrenceFrequency::NONE;

    /** Last day the recurrence reaches (inclusive); required when repeating, ignored otherwise. */
    public ?\DateTimeImmutable $repeatUntil = null;

    /**
     * Cross-field rules a single-field constraint cannot express: an end time only makes sense with a
     * start time, and it must come after it. No start time at all is fine — that is a reminder. Times
     * are "HH:MM" strings, so a lexicographic compare is a chronological compare.
     *
     * @param ExecutionContextInterface $context the validation context to attach violations to
     */
    #[Assert\Callback]
    public function validateTimes(ExecutionContextInterface $context): void
    {
        if (null === $this->startTime) {
            if (null !== $this->endTime) {
                $context->buildViolation('Pon primero la hora de inicio.')
                    ->atPath('endTime')
                    ->addViolation();
            }

            return;
        }
        if (null !== $this->endTime && $this->endTime <= $this->startTime) {
            $context->buildViolation('La hora de fin debe ser posterior a la de inicio.')
                ->atPath('endTime')
                ->addViolation();
        }
    }

    /**
     * Recurrence rules: a repeating entry needs an end day, no earlier than its start and within a
     * two-year horizon (so a single series can never materialise an unbounded number of occurrences).
     *
     * @param ExecutionContextInterface $context the validation context to attach violations to
     */
    #[Assert\Callback]
    public function validateRecurrence(ExecutionContextInterface $context): void
    {
        if (RecurrenceFrequency::NONE === $this->repeat || null === $this->day) {
            return;
        }
        if (null === $this->repeatUntil) {
            $context->buildViolation('Indica hasta qué día se repite.')
                ->atPath('repeatUntil')
                ->addViolation();

            return;
        }
        if ($this->repeatUntil < $this->day) {
            $context->buildViolation('La fecha de fin de la repetición no puede ser anterior al día del evento.')
                ->atPath('repeatUntil')
                ->addViolation();

            return;
        }
        if ($this->repeatUntil > $this->day->modify('+2 years')) {
            $context->buildViolation('La repetición no puede abarcar más de dos años.')
                ->atPath('repeatUntil')
                ->addViolation();
        }
    }

    /**
     * Prefills the form data from an existing entry (for editing), splitting its instants back into a
     * day and "HH:MM" times.
     *
     * @param PersonalEvent $event the entry to edit
     *
     * @return self the prefilled form data
     */
    public static function fromEvent(PersonalEvent $event): self
    {
        $data = new self();
        $data->title = $event->getTitle();
        $data->description = $event->getDescription();
        $data->day = $event->getStartAt()->setTime(0, 0);
        $data->category = $event->getCategory();
        // A timed entry prefills its time(s); a no-time reminder ({@see PersonalEvent::isAllDay()})
        // leaves them empty.
        if (!$event->isAllDay()) {
            $data->startTime = $event->getStartAt()->format('H:i');
            $data->endTime = $event->getEndAt()?->format('H:i');
        }

        return $data;
    }
}
