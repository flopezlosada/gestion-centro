<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Project;
use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Admin form for a {@see Project}: its name, who coordinates it and who is in it.
 *
 * The coordinator is a field of the project and NOT a ranked role, on purpose: coordinating a project
 * gives command over nobody, and it must not leak to other projects (see {@see Project}).
 *
 * @extends AbstractType<Project>
 */
final class ProjectType extends AbstractType
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $people = $this->users->findActive();

        $builder
            ->add('name', TextType::class, [
                'label' => 'Nombre',
                'help' => 'Por ejemplo: «Erasmus+», «Huerto escolar», «Plan digital».',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Descripción',
                'required' => false,
            ])
            ->add('coordinator', EntityType::class, [
                'label' => 'Coordina',
                'class' => User::class,
                'choices' => $people,
                'choice_label' => 'fullName',
                'required' => false,
                'placeholder' => '— Sin coordinador —',
                'help' => 'Quien puede convocar las reuniones del proyecto y subir sus actas. Sin coordinador, nadie convoca por el proyecto.',
            ])
            ->add('members', EntityType::class, [
                'label' => 'Profesorado del proyecto',
                'class' => User::class,
                'choices' => $people,
                'choice_label' => 'fullName',
                'multiple' => true,
                'expanded' => true,
                // Por los adders/removers de la entidad, para que desmarcar a alguien lo saque de verdad
                // del proyecto (mismo motivo que en UserType con los roles).
                'by_reference' => false,
                'choice_attr' => static fn (User $person): array => [
                    'data-description' => $person->getUnit()?->getName() ?? 'Sin departamento',
                ],
                'help' => 'Vienen convocados por defecto a las reuniones del proyecto.',
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'Proyecto en marcha',
                'required' => false,
                'help' => 'Desmárcalo cuando termine: deja de ofrecerse al convocar, pero sus reuniones y actas se conservan.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Project::class]);
    }
}
