<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One day of {@see FamilyAvailabilityFormType}: the two times. The day itself is not a field, it comes
 * with the row ({@see FamilyAvailabilityDay::$weekday}). Native time inputs, as in
 * {@see FamilyActivityFormType}.
 *
 * @extends AbstractType<FamilyAvailabilityDay>
 */
final class FamilyAvailabilityDayType extends AbstractType
{
    /** What both time fields share. */
    private const array TIME_FIELD = [
        'widget' => 'single_text',
        'input' => 'datetime_immutable',
        'required' => false,
        'invalid_message' => 'Pon la hora como HH:MM (por ejemplo, 10:30).',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('from', TimeType::class, ['label' => 'Desde', ...self::TIME_FIELD])
            ->add('until', TimeType::class, ['label' => 'Hasta', ...self::TIME_FIELD]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => FamilyAvailabilityDay::class]);
    }
}
