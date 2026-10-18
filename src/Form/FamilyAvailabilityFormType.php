<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * A family's availability: one row per day of the school week, given by the controller already filled
 * ({@see FamilyAvailabilityDay::of()}). Rows can be neither added nor removed, only their times changed.
 * Each row's own rules ({@see FamilyAvailabilityDay::validateSpan()}) need no `Valid`: the root form's
 * data is an array, and the validator walks into the objects it holds.
 *
 * @extends AbstractType<array{days: list<FamilyAvailabilityDay>}>
 */
final class FamilyAvailabilityFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('days', CollectionType::class, [
            'entry_type' => FamilyAvailabilityDayType::class,
            'entry_options' => ['label' => false],
            'label' => false,
        ]);
    }
}
