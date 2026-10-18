<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\FamilyActivity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What both kinds of activity open to families are asked in the form: what it is, where, and how many
 * families are wanted. Each kind adds its "when" ({@see OneOffFamilyActivityFormData},
 * {@see WeeklyFamilyActivityFormData}). The organiser is set by the controller, never by the form.
 */
abstract class FamilyActivityFormData
{
    #[Assert\NotBlank(message: 'El título es obligatorio.')]
    #[Assert\Length(max: 200)]
    public string $title = '';

    #[Assert\Length(max: 5000)]
    public ?string $description = null;

    #[Assert\Length(max: 120)]
    public ?string $place = null;

    #[Assert\Positive(message: 'Pon un número mayor que cero, o déjalo vacío.')]
    #[Assert\LessThanOrEqual(100, message: 'Como mucho {{ compared_value }} familias.')]
    public ?int $familiesWanted = null;

    /**
     * Copies what both kinds share onto the activity.
     *
     * @param FamilyActivity $activity the activity to update
     */
    public function applyDetailsTo(FamilyActivity $activity): void
    {
        $activity->setTitle($this->title)
            ->setDescription($this->description)
            ->setPlace($this->place)
            ->setFamiliesWanted($this->familiesWanted);
    }

    /**
     * Prefills what both kinds share from an existing activity (for editing).
     *
     * @param FamilyActivity $activity the activity to edit
     */
    protected function fillDetailsFrom(FamilyActivity $activity): void
    {
        $this->title = $activity->getTitle();
        $this->description = $activity->getDescription();
        $this->place = $activity->getPlace();
        $this->familiesWanted = $activity->getFamiliesWanted();
    }
}
