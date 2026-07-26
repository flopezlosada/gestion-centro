<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\EventCategory;
use App\Enum\EventReminderOffset;
use App\Enum\RecurrenceFrequency;
use App\Repository\EventCategoryRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Create/edit form for a personal agenda entry.
 *
 * The times are native `<input type="time">`. They used to be dropdowns of quarter-hour slots, which
 * meant scrolling (or searching!) a 96-item list that started at 00:00 just to pick 10:30 — and even
 * then, only quarters were representable. The native control types in four keystrokes on a desktop
 * and opens the system wheel on a phone. Unlike the DATE field (whose native picker the app replaces
 * with its own, because native date UX and dd/mm ordering vary wildly across systems), HH:MM is
 * unambiguous everywhere, so there is nothing to gain from reinventing it.
 *
 * @extends AbstractType<PersonalEventFormData>
 */
final class PersonalEventFormType extends AbstractType
{
    /**
     * What both time fields share.
     *
     * `input` is NOT 'string', tempting as it looks for a "HH:MM" DTO: with that setting an empty
     * field reaches the model as the empty STRING, not as null — the model transformer ends up calling
     * DateTimeToStringTransformer::transform(null), which returns ''. "Sin hora" would then read as
     * "hay hora" and every cross-field rule would misfire. With 'datetime_immutable' the null travels
     * all the way through, which is what an empty optional field means.
     */
    private const array TIME_FIELD = [
        'widget' => 'single_text',
        'input' => 'datetime_immutable',
        'required' => false,
        'invalid_message' => 'Pon la hora como HH:MM (por ejemplo, 10:30).',
    ];

    public function __construct(private readonly EventCategoryRepository $categories)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['label' => 'Título'])
            ->add('description', TextareaType::class, ['label' => 'Descripción', 'required' => false])
            ->add('category', EntityType::class, [
                'label' => 'Categoría',
                'class' => EventCategory::class,
                'choices' => $this->categories->findAllOrdered(),
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'Sin categoría',
                'help' => 'Le da un color en tu agenda y calendario.',
            ])
            ->add('day', DateType::class, [
                'label' => 'Día',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('startTime', TimeType::class, [
                'label' => 'Hora',
                ...self::TIME_FIELD,
                'help' => 'Déjalo vacío si es un recordatorio sin hora concreta.',
            ])
            ->add('endTime', TimeType::class, [
                'label' => 'Hasta',
                ...self::TIME_FIELD,
                'help' => 'Opcional, si tiene una hora de fin.',
            ])
            ->add('reminder', EnumType::class, [
                'label' => 'Avisarme',
                'class' => EventReminderOffset::class,
                'choice_label' => static fn (EventReminderOffset $offset): string => $offset->label(),
                'required' => false,
                'placeholder' => '— Sin aviso —',
                'help' => 'Te llega una notificación al móvil. Requiere tener los avisos activados en este dispositivo (se activan en «Avisos»).',
            ]);

        // Recurrence is a create-time decision: once materialised into occurrences, each is edited on
        // its own. So the fields appear only when the controller asks for them (on the new form).
        if (true === $options['include_recurrence']) {
            $builder
                ->add('repeat', EnumType::class, [
                    'label' => 'Repetir',
                    'class' => RecurrenceFrequency::class,
                    'choice_label' => static fn (RecurrenceFrequency $frequency): string => $frequency->label(),
                ])
                ->add('repeatUntil', DateType::class, [
                    'label' => 'Repetir hasta',
                    'widget' => 'single_text',
                    'input' => 'datetime_immutable',
                    'required' => false,
                    'help' => 'Solo si se repite: el último día en que aparece.',
                ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PersonalEventFormData::class,
            'include_recurrence' => false,
        ]);
        $resolver->setAllowedTypes('include_recurrence', 'bool');
    }
}
