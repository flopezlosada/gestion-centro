<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Room;
use App\Repository\RoomRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * "Esto ocupa estas aulas, estos días, estas horas" — the whole occupation of an event in one form.
 *
 * Exists because of what the centre said about exam week (2026-07-30): the exams take the English rooms
 * for FOUR days. Stating that one activity at a time is four days × four rooms = sixteen identical
 * forms, and a person filling in sixteen identical forms makes mistakes on the fourteenth. Here they
 * pick the rooms, the range of days and the periods once, and the screen creates the lines.
 *
 * The same form covers the cultural days, which the centre brings already decided ("cada grupo entero va
 * a un taller, a veces varios grupos al mismo taller"): name the workshop, say which groups go and when,
 * and leave the rooms EMPTY — then the engine finds each session a room big enough for those groups.
 *
 * It is not backed by an entity: the controller turns it into as many {@see \App\Entity\SpacePlanActivity}
 * as it describes.
 *
 * @extends AbstractType<array{title: string, rooms: list<Room>, groups: list<string>, from: \DateTimeImmutable, to: \DateTimeImmutable, slots: list<int>}>
 */
final class SpaceOccupationBlockType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string, int> $slotChoices */
        $slotChoices = $options['slot_choices'];

        $builder
            ->add('title', TextType::class, [
                'label' => 'Qué es',
                'help' => 'Lo que ocupa las aulas: "Exámenes de 2º de Bachillerato", "Prueba de la EOI".',
                'constraints' => [new NotBlank(message: 'Di qué ocupa las aulas.')],
            ])
            ->add('rooms', EntityType::class, [
                'label' => 'Aulas que ocupa',
                'class' => Room::class,
                'query_builder' => static fn (RoomRepository $r) => $r->createQueryBuilder('r')->andWhere('r.active = true')->orderBy('r.code', 'ASC'),
                'choice_label' => static fn (Room $r): string => $r->getLabel(),
                'multiple' => true,
                'expanded' => false,
                'required' => false,
                'help' => 'Las que se quedan tomadas (para la semana de exámenes, donde se examina). Déjalo VACÍO en un taller para que el programa le busque aula.',
            ])
            ->add('groups', ChoiceType::class, [
                'label' => 'Grupos que van',
                'choices' => $options['group_choices'],
                'multiple' => true,
                'expanded' => false,
                'required' => false,
                'help' => 'Para un taller de jornadas culturales: qué grupos asisten. De aquí sale el tamaño de aula que necesita.',
            ])
            ->add('from', DateType::class, [
                'label' => 'Desde el día',
                'widget' => 'single_text',
                'constraints' => [new NotNull(message: 'Indica el primer día.')],
            ])
            ->add('to', DateType::class, [
                'label' => 'Hasta el día',
                'widget' => 'single_text',
                'constraints' => [new NotNull(message: 'Indica el último día.')],
                'help' => 'El mismo día si dura solo uno. Los días no lectivos se saltan solos.',
            ])
            ->add('slots', ChoiceType::class, [
                'label' => 'Horas que ocupa cada día',
                'choices' => $slotChoices,
                'multiple' => true,
                'expanded' => true,
                'constraints' => [new Count(min: 1, minMessage: 'Elige al menos una hora.')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['slot_choices' => [], 'group_choices' => []]);
        $resolver->setAllowedTypes('slot_choices', 'array');
        $resolver->setAllowedTypes('group_choices', 'array');
    }
}
