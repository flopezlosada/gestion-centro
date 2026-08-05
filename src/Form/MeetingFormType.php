<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\MeetingType;
use App\Entity\Project;
use App\Entity\User;
use App\Enum\EventReminderOffset;
use App\Enum\MeetingScope;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
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
            // Con quién es la reunión. Va lo PRIMERO porque decide el resto de la pantalla: con alumnado
            // o con familias no hay acta que rellenar (eso se registra en RAICES) y meeting-form.js
            // esconde esos cuadros.
            ->add('scope', EnumType::class, [
                'class' => MeetingScope::class,
                'label' => '¿Con quién es?',
                'expanded' => true,
                'choice_label' => static fn (MeetingScope $s): string => $s->label(),
                'choice_attr' => static fn (MeetingScope $s): array => ['data-keeps-minutes' => $s->keepsMinutes() ? '1' : '0'],
            ])
            ->add('type', EntityType::class, [
                'label' => 'Tipo de reunión',
                'class' => MeetingType::class,
                'choices' => $options['type_choices'],
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => '— Sin tipo —',
                'help' => 'Sirve para archivar el acta. La lista la mantiene administración.',
                'row_attr' => ['data-staff-only' => '1'],
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
            ->add('reminder', EnumType::class, [
                'label' => 'Avisar antes',
                'class' => EventReminderOffset::class,
                'choice_label' => static fn (EventReminderOffset $offset): string => $offset->label(),
                'required' => false,
                'placeholder' => '— Sin aviso —',
                'help' => 'Aviso al móvil para todos los convocados (tú incluida) cuando se acerque la hora. Requiere tener los avisos activados en el dispositivo.',
            ])
            ->add('agenda', TextareaType::class, [
                'label' => 'Orden del día',
                'required' => false,
                'help' => 'Los puntos a tratar. Lo verán los convocados.',
                'row_attr' => ['data-staff-only' => '1'],
            ])
            ->add('project', EntityType::class, [
                'label' => 'Proyecto',
                'class' => Project::class,
                'choices' => $options['project_choices'],
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => '— No es de un proyecto —',
                // Ya no es "solo los que llevas": el equipo directivo ve los del centro entero
                // ({@see \App\Service\MeetingAccess::convenableProjects()}), que es lo que le faltaba para
                // poder convocar la reunión periódica de un proyecto y archivar su acta.
                'help' => 'Los proyectos y coordinaciones que llevas (o los del centro, si estás en el equipo directivo). Es lo que hace que el acta quede archivada como del proyecto; al convocar desde uno, su profesorado viene ya marcado.',
            ])
            ->add('minutesTakenBy', EntityType::class, [
                'label' => 'Levanta el acta',
                'class' => User::class,
                'choices' => $options['attendee_choices'],
                'choice_label' => 'fullName',
                'required' => false,
                'placeholder' => 'Quien convoca (tú)',
                'help' => 'En un órgano colegiado (CCP, claustro) es la secretaría; en un departamento, su jefatura; en un proyecto, su coordinación. Quien levanta el acta es quien la sube, pasa lista y la da por aprobada.',
            ])
            ->add('minutesApprovalRequired', CheckboxType::class, [
                'label' => 'El acta se aprueba en la reunión siguiente',
                'required' => false,
                'help' => 'Márcalo en la CCP y en las reuniones de departamento. En el resto no hace falta.',
            ])
            ->add('attendees', EntityType::class, [
                'label' => 'Personas convocadas',
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
                'help' => 'Quien esté en la lista recibe un aviso con el día, la hora y el lugar. Tú convocas, así que no hace falta marcarte.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MeetingFormData::class,
            'project_choices' => [],
            'attendee_choices' => [],
            'type_choices' => [],
        ]);
        $resolver->setAllowedTypes('project_choices', 'array');
        $resolver->setAllowedTypes('attendee_choices', 'array');
        $resolver->setAllowedTypes('type_choices', 'array');
    }
}
