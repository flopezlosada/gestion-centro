<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\WeeklyFamilyActivity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form-backing object for a {@see WeeklyFamilyActivity}: the details, and which of the teacher's classes
 * it is held in, as "weekday-period" keys of {@see \App\Family\FamilySessions::classesOf()}. Whether they
 * can go together is the activity's own rule, {@see WeeklyFamilyActivity::problemWith()}.
 */
final class WeeklyFamilyActivityFormData extends FamilyActivityFormData
{
    /** @var list<string> */
    #[Assert\Count(min: 1, minMessage: 'Elige al menos una clase.')]
    public array $classKeys = [];

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
        $data->classKeys = self::keysOf($activity);

        return $data;
    }

    /**
     * @param WeeklyFamilyActivity $activity a weekly activity
     *
     * @return list<string> the "weekday-period" keys of its classes
     */
    public static function keysOf(WeeklyFamilyActivity $activity): array
    {
        return array_map(static fn (array $t): string => $t['weekday']->value.'-'.$t['slot'], $activity->classTimes());
    }
}
