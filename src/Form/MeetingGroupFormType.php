<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\MeetingGroup;
use App\Entity\MeetingType;
use App\Entity\User;
use App\Enum\Weekday;
use App\Repository\MeetingTypeRepository;
use App\Repository\UserRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Alta y edición de un grupo de convocatoria: su nombre y quién lo compone, y, si se reúne cada semana,
 * qué día y a qué hora, quién convoca y de qué tipo es la reunión.
 *
 * El día y la hora van SIN mapear: la entidad solo los acepta juntos ({@see MeetingGroup::repeatWeekly()}),
 * así que los aplica el controlador después de comprobar que vienen los dos o ninguno.
 *
 * @extends AbstractType<MeetingGroup>
 */
final class MeetingGroupFormType extends AbstractType
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly MeetingTypeRepository $meetingTypes,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nombre',
                'help' => 'Como se va a reconocer al convocar: «Tutores/as 2º ESO», «CCP»…',
            ])
            ->add('members', EntityType::class, [
                'label' => 'Quién lo forma',
                'class' => User::class,
                'choices' => $this->users->findActive(),
                'choice_label' => 'fullName',
                'multiple' => true,
                'expanded' => true,
                // Por los adders/removers de la entidad, como en ProjectType: desmarcar a alguien lo
                // saca de verdad del grupo.
                'by_reference' => false,
                'choice_attr' => static fn (User $person): array => [
                    'data-description' => $person->getUnit()?->getName() ?? 'Sin departamento',
                ],
                'help' => 'Se marcan como convocados por defecto al usar este grupo; se pueden quitar o añadir más al convocar, sin que eso cambie el grupo guardado aquí.',
            ])
            ->add('weekday', EnumType::class, [
                'label' => 'Día',
                'class' => Weekday::class,
                'choices' => Weekday::schoolWeek(),
                'choice_label' => static fn (Weekday $day): string => $day->label(),
                'mapped' => false,
                'required' => false,
                'placeholder' => 'No se repite',
            ])
            ->add('slotIndex', ChoiceType::class, [
                'label' => 'Hora',
                'choices' => $options['slot_choices'],
                'mapped' => false,
                'required' => false,
                'placeholder' => '—',
            ])
            ->add('convener', EntityType::class, [
                'label' => 'Quién convoca',
                'class' => User::class,
                'choices' => $this->users->findActive(),
                'choice_label' => 'fullName',
                'required' => false,
                'placeholder' => 'Nadie todavía',
                'help' => 'Convoca y levanta el acta de las reuniones que se crean solas. Sin nadie aquí, no se crea ninguna.',
            ])
            ->add('type', EntityType::class, [
                'label' => 'Tipo de reunión',
                'class' => MeetingType::class,
                'choices' => $this->meetingTypes->findActive(),
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'Sin tipo',
                'help' => 'Decide si el acta se aprueba en la reunión siguiente.',
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'En uso',
                'required' => false,
                'help' => 'Al desmarcarlo deja de ofrecerse como atajo al convocar.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => MeetingGroup::class, 'slot_choices' => []]);
        $resolver->setAllowedTypes('slot_choices', 'array');
    }
}
