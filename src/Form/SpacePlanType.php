<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\SpacePlan;
use App\Enum\SpacePlanKind;
use App\Enum\SubstitutionScope;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The enunciado of a space plan: what it is, when, and whose timetable it replaces.
 *
 * Periods and groups are offered as lists built from the course's own timetable, never typed in: a
 * period index or a group name that does not match the timetable exactly would silently do nothing, and
 * the person would only find out when the proposals came back empty.
 *
 * @extends AbstractType<SpacePlan>
 */
final class SpacePlanType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string, int> $slotChoices */
        $slotChoices = $options['slot_choices'];
        /** @var array<string, string> $groupChoices */
        $groupChoices = $options['group_choices'];

        $builder
            ->add('title', TextType::class, [
                'label' => 'Título',
                'help' => 'Lo que encabezará el documento: "Talleres de Cruz Roja, 3-5 de marzo".',
            ])
            ->add('kind', EnumType::class, [
                'label' => 'Tipo',
                'class' => SpacePlanKind::class,
                'choices' => SpacePlanKind::inDisplayOrder(),
                'choice_label' => static fn (SpacePlanKind $k): string => $k->label(),
                'help' => 'Solo sirve para ordenar y para preseleccionar el resto: el mecanismo es el mismo en los tres casos.',
            ])
            ->add('dateFrom', DateType::class, [
                'label' => 'Desde el día',
                'widget' => 'single_text',
            ])
            ->add('dateTo', DateType::class, [
                'label' => 'Hasta el día',
                'widget' => 'single_text',
                'help' => 'El mismo día si dura solo uno.',
            ])
            ->add('slotFrom', ChoiceType::class, [
                'label' => 'Desde la hora',
                'choices' => $slotChoices,
                'required' => false,
                'placeholder' => 'Desde la primera',
            ])
            ->add('slotTo', ChoiceType::class, [
                'label' => 'Hasta la hora',
                'choices' => $slotChoices,
                'required' => false,
                'placeholder' => 'Hasta la última',
            ])
            ->add('substitutionScope', EnumType::class, [
                'label' => '¿A quién le desaparece su horario normal?',
                'class' => SubstitutionScope::class,
                'choices' => SubstitutionScope::inDisplayOrder(),
                'choice_label' => static fn (SubstitutionScope $s): string => $s->label(),
                'help' => 'Un cambio de aula no le quita la clase a nadie. Una semana de exámenes sí se la quita al nivel que examina; unas jornadas, a todo el centro.',
            ])
            ->add('scopeGroupNames', ChoiceType::class, [
                'label' => 'Grupos afectados',
                'choices' => $groupChoices,
                'multiple' => true,
                'expanded' => false,
                'required' => false,
                'help' => 'Solo si has elegido "solo los grupos que indique".',
            ])
            ->add('publicReason', TextType::class, [
                'label' => 'Motivo (se publica)',
                'required' => false,
                'help' => 'Lo leerán el profesorado avisado y quien mire el tablón: "por motivos organizativos", "por la prueba de la EOI".',
            ])
            ->add('internalNotes', TextareaType::class, [
                'label' => 'Notas internas',
                'required' => false,
                'help' => 'No se publica ni se envía a nadie.',
                'attr' => ['rows' => 3],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SpacePlan::class,
            'slot_choices' => [],
            'group_choices' => [],
        ]);
        $resolver->setAllowedTypes('slot_choices', 'array');
        $resolver->setAllowedTypes('group_choices', 'array');
    }
}
