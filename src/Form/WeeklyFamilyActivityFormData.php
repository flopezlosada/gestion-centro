<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\WeeklyFamilyActivity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form-backing object for a {@see WeeklyFamilyActivity}: the details, and which of the teacher's classes
 * it is held in, as the "weekday-period" key of {@see \App\Family\FamilySessions::classesOf()}.
 */
final class WeeklyFamilyActivityFormData extends FamilyActivityFormData
{
    #[Assert\NotBlank(message: 'Elige en cuál de tus clases.')]
    public ?string $classKey = null;

    /**
     * Prefills the form data from an existing activity (for editing).
     *
     * @param WeeklyFamilyActivity $activity the activity to edit
     *
     * @return self the prefilled form data
     */
    public static function fromActivity(WeeklyFamilyActivity $activity): self
    {
        $data = new self();
        $data->fillDetailsFrom($activity);
        $data->classKey = self::keyOf($activity);

        return $data;
    }

    /**
     * @param WeeklyFamilyActivity $activity a weekly activity
     *
     * @return string the "weekday-period" key of its class
     */
    public static function keyOf(WeeklyFamilyActivity $activity): string
    {
        return $activity->getWeekday()->value.'-'.$activity->getSlotIndex();
    }
}
