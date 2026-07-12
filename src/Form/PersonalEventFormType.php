<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
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

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $slots = $this->timeSlots();

        $builder
            ->add('title', TextType::class, ['label' => 'Título'])
            ->add('description', TextareaType::class, ['label' => 'Descripción', 'required' => false])
            ->add('day', DateType::class, [
                'label' => 'Día',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('allDay', CheckboxType::class, [
                'label' => 'Todo el día',
                'required' => false,
                'help' => 'Sin hora concreta: solo ocupa el día en tu agenda.',
            ])
            ->add('startTime', ChoiceType::class, [
                'label' => 'Desde',
                'required' => false,
                'placeholder' => '— Hora —',
                'choices' => $slots,
            ])
            ->add('endTime', ChoiceType::class, [
                'label' => 'Hasta',
                'required' => false,
                'placeholder' => '— Sin fin —',
                'choices' => $slots,
                'help' => 'Opcional. Déjalo vacío si no tiene una hora de fin.',
            ]);
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
        $resolver->setDefaults(['data_class' => PersonalEventFormData::class]);
    }
}
