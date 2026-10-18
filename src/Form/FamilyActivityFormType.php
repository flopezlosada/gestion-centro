<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Create/edit form for an activity open to families. Native time inputs, for the reasons given in
 * {@see PersonalEventFormType}, including why `input` is 'datetime_immutable' and not 'string'.
 *
 * @extends AbstractType<FamilyActivityFormData>
 */
final class FamilyActivityFormType extends AbstractType
{
    /** What both time fields share. */
    private const array TIME_FIELD = [
        'widget' => 'single_text',
        'input' => 'datetime_immutable',
        'invalid_message' => 'Pon la hora como HH:MM (por ejemplo, 10:30).',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['label' => 'Título'])
            ->add('description', TextareaType::class, [
                'label' => 'Descripción',
                'required' => false,
                'help' => 'Qué se hace y qué tienen que saber las familias que vengan.',
            ])
            ->add('place', TextType::class, ['label' => 'Lugar', 'required' => false])
            ->add('day', DateType::class, [
                'label' => 'Día',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('startTime', TimeType::class, ['label' => 'Hora', ...self::TIME_FIELD])
            ->add('endTime', TimeType::class, [
                'label' => 'Hasta',
                ...self::TIME_FIELD,
                'required' => false,
                'help' => 'Opcional, si tiene una hora de fin.',
            ])
            ->add('familiesWanted', IntegerType::class, [
                'label' => 'Familias que hacen falta',
                'required' => false,
                'attr' => ['min' => 1, 'max' => 100],
                'help' => 'Déjalo vacío si cualquier número ayuda.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => FamilyActivityFormData::class]);
    }
}
