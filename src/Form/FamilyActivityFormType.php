<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Create/edit form for an activity open to families, of either kind. With `class_choices` it is a weekly
 * one, held in the classes picked from those choices; without, a one-off with a day and times of its own.
 * Native time inputs, for the reasons given in {@see PersonalEventFormType}, including why `input` is
 * 'datetime_immutable' and not 'string'.
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
                'help' => 'Qué se hace y qué tiene que saber quien venga.',
            ])
            ->add('familiesWanted', IntegerType::class, [
                'label' => 'Personas que hacen falta',
                'required' => false,
                'attr' => ['min' => 1, 'max' => 100],
                'help' => null !== $options['class_choices'] ? 'En cada sesión. Déjalo vacío si cualquier número ayuda.' : 'Déjalo vacío si cualquier número ayuda.',
            ]);

        if (null !== $options['class_choices']) {
            // Checkboxes, laid out by the template as the teacher's timetable; the classes' rooms are the place.
            $builder->add('classKeys', ChoiceType::class, [
                'label' => 'En cuáles de tus clases',
                'choices' => $options['class_choices'],
                'expanded' => true,
                'multiple' => true,
                'help' => 'Toca una o varias clases del mismo grupo. Habrá sesión cada semana que las tengas: los festivos y las vacaciones no cuentan. Dos horas seguidas el mismo día son una sola sesión.',
            ]);

            return;
        }
        $builder
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
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => FamilyActivityFormData::class,
            // Label => "weekday-period" key of the teacher's classes, for a weekly activity; null for a one-off.
            'class_choices' => null,
        ]);
        $resolver->setAllowedTypes('class_choices', ['null', 'array']);
    }
}
