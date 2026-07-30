<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Room;
use App\Entity\SpacePlanActivity;
use App\Enum\RoomKind;
use App\Repository\RoomRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * What the event brings into the centre: the external exam that takes the assembly hall on Tuesday, the
 * workshop that needs a room for three sessions.
 *
 * The same form covers both, because they are the same thing with different blanks filled in — leaving
 * the room and the day empty means "find somewhere", which is what the cultural-days engine will place.
 *
 * @extends AbstractType<SpacePlanActivity>
 */
final class SpacePlanActivityType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string, int> $slotChoices */
        $slotChoices = $options['slot_choices'];

        $builder
            ->add('title', TextType::class, [
                'label' => 'Qué es',
                'help' => '"Prueba de la EOI", "Taller de primeros auxilios", "Examen de Matemáticas II".',
            ])
            ->add('room', EntityType::class, [
                'label' => 'Espacio que ocupa',
                'class' => Room::class,
                'query_builder' => static fn (RoomRepository $r) => $r->createQueryBuilder('r')->andWhere('r.active = true')->orderBy('r.code', 'ASC'),
                'choice_label' => static fn (Room $r): string => $r->getLabel(),
                'required' => false,
                'placeholder' => 'Que lo decida el programa',
                'help' => 'Déjalo sin elegir si te da igual dónde sea y prefieres que lo busque el programa.',
            ])
            ->add('fixedDate', DateType::class, [
                'label' => 'Día',
                'widget' => 'single_text',
                'required' => false,
                'help' => 'Dentro de las fechas del plan.',
            ])
            ->add('fixedSlots', ChoiceType::class, [
                'label' => 'Horas que ocupa',
                'choices' => $slotChoices,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('requiredCapacity', IntegerType::class, [
                'label' => 'Cuánta gente',
                'required' => false,
                'help' => 'Solo si hace falta un aforo mínimo.',
            ])
            ->add('requiredKind', EnumType::class, [
                'label' => 'Tipo de espacio necesario',
                'class' => RoomKind::class,
                'choices' => RoomKind::inDisplayOrder(),
                'choice_label' => static fn (RoomKind $k): string => $k->label(),
                'required' => false,
                'placeholder' => 'Cualquiera',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SpacePlanActivity::class,
            'slot_choices' => [],
        ]);
        $resolver->setAllowedTypes('slot_choices', 'array');
    }
}
