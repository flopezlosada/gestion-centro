<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\OneOffFamilyActivity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Form-backing object for a {@see OneOffFamilyActivity}. A DTO for the same reason as
 * {@see PersonalEventFormData}: the entity stores instants, the form asks for a day plus "from"/"until"
 * times. Unlike a personal entry, the start time is required — a family has to know when to come.
 */
final class OneOffFamilyActivityFormData extends FamilyActivityFormData
{
    #[Assert\NotNull(message: 'Pon el día.')]
    public ?\DateTimeImmutable $day = null;

    /** Only the hour and minute carry meaning; they are composed onto {@see $day}. */
    #[Assert\NotNull(message: 'Pon la hora a la que empieza.')]
    public ?\DateTimeImmutable $startTime = null;

    /** End time, or null when only the start is known. */
    public ?\DateTimeImmutable $endTime = null;

    /**
     * A day of the school week, and the end after the start. Compares the "HH:MM" of each, not the instants: only the time
     * of day is meaningful here, whatever date the time fields happen to carry.
     *
     * @param ExecutionContextInterface $context the validation context to attach violations to
     */
    #[Assert\Callback]
    public function validateTimes(ExecutionContextInterface $context): void
    {
        // The calendar families sign up from is the school week: a Saturday would be published and never shown.
        if (null !== $this->day && (int) $this->day->format('N') > 5) {
            $context->buildViolation('Elige un día de lunes a viernes.')->atPath('day')->addViolation();
        }
        if (null === $this->startTime || null === $this->endTime) {
            return;
        }
        if ($this->endTime->format('H:i') <= $this->startTime->format('H:i')) {
            $context->buildViolation('La hora de fin debe ser posterior a la de inicio.')
                ->atPath('endTime')
                ->addViolation();
        }
    }

    /**
     * Prefills the form data from an existing activity (for editing).
     *
     * @param OneOffFamilyActivity $activity the activity to edit
     *
     * @return self the prefilled form data
     */
    public static function fromActivity(OneOffFamilyActivity $activity): self
    {
        $data = new self();
        $data->fillDetailsFrom($activity);
        $data->day = $activity->getStartAt()->setTime(0, 0);
        $data->startTime = $activity->getStartAt();
        $data->endTime = $activity->getEndAt();

        return $data;
    }
}
