<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Project;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Convocatoria form: what is being met about, when, where, and who is called.
 *
 * The choices are passed in by the controller ({@see \App\Service\MeetingAccess}), never resolved here:
 * who you may convene depends on who YOU are (the projects you coordinate and the departments you
 * command), so it cannot be a property of the form type.
 *
 * The people are CHECKBOXES (expanded), not a multi-select: a native multi-select needs ctrl-clicking
 * to pick several, which on a phone is unusable and on a desktop is a well-known trap — and the whole
 * point of this screen is picking several people.
 *
 * @extends AbstractType<MeetingFormData>
 */
final class MeetingFormType extends AbstractType
{
    /**
     * What both time fields share. `input` is deliberately 'datetime_immutable' and not 'string': with a
     * string an empty field arrives as '' instead of null, so "sin hora de fin" would read as "hay hora"
     * (the same trap documented in {@see PersonalEventFormType}).
     */
    private const array TIME_FIELD = [
        'widget' => 'single_text',
        'input' => 'datetime_immutable',
        'required' => false,
        'invalid_message' => 'Pon la hora como HH:MM (por ejemplo, 14:00).',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Título',
                'help' => 'Por ejemplo: «Reunión de departamento de septiembre».',
            ])
            ->add('day', DateType::class, [
                'label' => 'Día',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('startTime', TimeType::class, [
                ...self::TIME_FIELD,
                'label' => 'Hora',
                'required' => true,
            ])
            ->add('endTime', TimeType::class, [
                ...self::TIME_FIELD,
                'label' => 'Hasta',
                'help' => 'Opcional.',
            ])
            ->add('place', TextType::class, [
                'label' => 'Lugar',
                'required' => false,
                'help' => 'Sala de profesores, aula 12, videollamada…',
            ])
            ->add('agenda', TextareaType::class, [
                'label' => 'Orden del día',
                'required' => false,
                'help' => 'Los puntos a tratar. Lo verán los convocados.',
            ])
            ->add('project', EntityType::class, [
                'label' => 'Proyecto',
                'class' => Project::class,
                'choices' => $options['project_choices'],
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => '— No es de un proyecto —',
                'help' => 'Solo los proyectos que coordinas. Al convocar desde un proyecto, sus profes vienen ya marcados.',
            ])
            ->add('attendees', EntityType::class, [
                'label' => 'Convocados',
                'class' => User::class,
                'choices' => $options['attendee_choices'],
                'choice_label' => 'fullName',
                'multiple' => true,
                'expanded' => true,
                // El departamento de cada persona viaja a su casilla para que la lista distinga a dos
                // compañeros de nombre parecido sin salir de la pantalla.
                'choice_attr' => static fn (User $person): array => [
                    'data-description' => $person->getUnit()?->getName() ?? 'Sin departamento',
                ],
                'help' => 'Recibirán un aviso con el día, la hora y el lugar. Tú cuentas como convocante, no hace falta marcarte.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MeetingFormData::class,
            'project_choices' => [],
            'attendee_choices' => [],
        ]);
        $resolver->setAllowedTypes('project_choices', 'array');
        $resolver->setAllowedTypes('attendee_choices', 'array');
    }
}
