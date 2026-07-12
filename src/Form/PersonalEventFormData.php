<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\PersonalEvent;
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

    public bool $allDay = false;

    /** Start time as "HH:MM", or null for an all-day entry. */
    public ?string $startTime = null;

    /** End time as "HH:MM", or null when the entry has no explicit end. */
    public ?string $endTime = null;

    /**
     * Cross-field rules that a single-field constraint cannot express: a timed entry needs a start,
     * and the end (if any) must come after it. Times are "HH:MM" strings, so a lexicographic compare
     * is a chronological compare.
     *
     * @param ExecutionContextInterface $context the validation context to attach violations to
     */
    #[Assert\Callback]
    public function validateTimes(ExecutionContextInterface $context): void
    {
        if ($this->allDay) {
            return;
        }
        if (null === $this->startTime) {
            $context->buildViolation('Indica la hora de inicio o marca «Todo el día».')
                ->atPath('startTime')
                ->addViolation();

            return;
        }
        if (null !== $this->endTime && $this->endTime <= $this->startTime) {
            $context->buildViolation('La hora de fin debe ser posterior a la de inicio.')
                ->atPath('endTime')
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
        $data->allDay = $event->isAllDay();
        if (!$event->isAllDay()) {
            $data->startTime = $event->getStartAt()->format('H:i');
            $data->endTime = $event->getEndAt()?->format('H:i');
        }

        return $data;
    }
}
