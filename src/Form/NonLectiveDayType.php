<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\NonLectiveDay;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Admin form to register/edit a {@see NonLectiveDay}: its date and a short reason. Weekends are not
 * managed here (they are non-teaching by definition); this is for holidays, festivities and one-off
 * closures.
 *
 * @extends AbstractType<NonLectiveDay>
 */
final class NonLectiveDayType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('date', DateType::class, [
                'label' => 'Fecha',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'help' => 'El día que no será lectivo. Los fines de semana ya lo son, no hace falta añadirlos.',
            ])
            ->add('description', TextType::class, [
                'label' => 'Descripción',
                'help' => 'Motivo visible en el calendario, p. ej. "Fiesta local" o "Vacaciones de Navidad".',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => NonLectiveDay::class,
        ]);
    }
}
