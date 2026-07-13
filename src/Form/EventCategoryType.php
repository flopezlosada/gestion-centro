<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\EventCategory;
use App\Enum\CategoryColor;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Admin form to create/edit an {@see EventCategory}: a name and a colour from the fixed palette. The
 * colour is an expanded {@see EnumType} (radios) so the template can render it as a swatch picker.
 *
 * @extends AbstractType<EventCategory>
 */
final class EventCategoryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nombre',
                'help' => 'Cómo la verás al etiquetar un evento, p. ej. "Tutoría" o "Claustro".',
            ])
            ->add('color', EnumType::class, [
                'label' => 'Color',
                'class' => CategoryColor::class,
                'choice_label' => static fn (CategoryColor $color): string => $color->label(),
                'expanded' => true,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => EventCategory::class]);
    }
}
