<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\EventCategory;
use App\Enum\RecurrenceFrequency;
use App\Repository\EventCategoryRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Create/edit form for a personal agenda entry. The times are plain {@see ChoiceType} dropdowns of
 * quarter-hour slots (not a native time picker), so they inherit the app's own combobox styling —
 * no extra widget needed. The day reuses the app's date field.
 *
 * @extends AbstractType<PersonalEventFormData>
 */
final class PersonalEventFormType extends AbstractType
{
    /**
     * Quarter-hour slots across the whole day (minutes since midnight). This is a personal diary, so
     * no reminder time should be unrepresentable; the searchable combobox keeps the long list usable.
     */
    private const int SLOT_FROM = 0;             // 00:00
    private const int SLOT_TO = 23 * 60 + 45;    // 23:45
    private const int SLOT_STEP = 15;            // quarter-hour granularity

    public function __construct(private readonly EventCategoryRepository $categories)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $slots = $this->timeSlots();

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
            ->add('startTime', ChoiceType::class, [
                'label' => 'Hora',
                'required' => false,
                'placeholder' => '— Sin hora —',
                'choices' => $slots,
                'help' => 'Déjalo en «Sin hora» si es un recordatorio sin hora concreta.',
            ])
            ->add('endTime', ChoiceType::class, [
                'label' => 'Hasta',
                'required' => false,
                'placeholder' => '— Sin fin —',
                'choices' => $slots,
                'help' => 'Opcional, si tiene una hora de fin.',
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

    /**
     * The quarter-hour slots offered by the "from"/"until" dropdowns, as label => value with both in
     * "HH:MM" form (so the stored value is a chronological string).
     *
     * @return array<string, string> the slots, keyed by their own label
     */
    private function timeSlots(): array
    {
        $slots = [];
        for ($minutes = self::SLOT_FROM; $minutes <= self::SLOT_TO; $minutes += self::SLOT_STEP) {
            $label = \sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
            $slots[$label] = $label;
        }

        return $slots;
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
