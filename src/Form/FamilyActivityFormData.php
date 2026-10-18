<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\FamilyActivity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Form-backing object for a {@see FamilyActivity}. A DTO for the same reason as
 * {@see PersonalEventFormData}: the entity stores instants, the form asks for a day plus "from"/"until"
 * times. Unlike a personal entry, the start time is required — a family has to know when to come.
 * The organiser is set by the controller, never by the form.
 */
final class FamilyActivityFormData
{
    #[Assert\NotBlank(message: 'El título es obligatorio.')]
    #[Assert\Length(max: 200)]
    public string $title = '';

    #[Assert\Length(max: 5000)]
    public ?string $description = null;

    #[Assert\Length(max: 120)]
    public ?string $place = null;

    #[Assert\NotNull(message: 'Pon el día.')]
    public ?\DateTimeImmutable $day = null;

    /** Only the hour and minute carry meaning; they are composed onto {@see $day}. */
    #[Assert\NotNull(message: 'Pon la hora a la que empieza.')]
    public ?\DateTimeImmutable $startTime = null;

    /** End time, or null when only the start is known. */
    public ?\DateTimeImmutable $endTime = null;

    #[Assert\Positive(message: 'Pon un número mayor que cero, o déjalo vacío.')]
    #[Assert\LessThanOrEqual(100, message: 'Como mucho {{ compared_value }} familias.')]
    public ?int $familiesWanted = null;

    /**
     * The end must come after the start. Compares the "HH:MM" of each, not the instants: only the time
     * of day is meaningful here, whatever date the time fields happen to carry.
     *
     * @param ExecutionContextInterface $context the validation context to attach violations to
     */
    #[Assert\Callback]
    public function validateTimes(ExecutionContextInterface $context): void
    {
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
     * @param FamilyActivity $activity the activity to edit
     *
     * @return self the prefilled form data
     */
    public static function fromActivity(FamilyActivity $activity): self
    {
        $data = new self();
        $data->title = $activity->getTitle();
        $data->description = $activity->getDescription();
        $data->place = $activity->getPlace();
        $data->day = $activity->getStartAt()->setTime(0, 0);
        $data->startTime = $activity->getStartAt();
        $data->endTime = $activity->getEndAt();
        $data->familiesWanted = $activity->getFamiliesWanted();

        return $data;
    }
}
