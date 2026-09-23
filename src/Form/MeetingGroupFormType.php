<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\MeetingGroup;
use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Alta y edición de un grupo de convocatoria: su nombre y quién lo compone. Igual de corto que
 * {@see MeetingTypeFormType} por la misma razón — es una lista guardada, no una función nueva.
 *
 * @extends AbstractType<MeetingGroup>
 */
final class MeetingGroupFormType extends AbstractType
{
    public function __construct(private readonly UserRepository $users)
    {
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
            ->add('active', CheckboxType::class, [
                'label' => 'En uso',
                'required' => false,
                'help' => 'Al desmarcarlo deja de ofrecerse como atajo al convocar.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => MeetingGroup::class]);
    }
}
