<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Material;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Alta y edición de material reservable.
 *
 * @extends AbstractType<Material>
 */
final class MaterialFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nombre',
                'help' => 'Uno por cada cosa que se coge por separado: «Carro de portátiles A» y «Carro de portátiles B» son dos.',
            ])
            ->add('keptAt', TextType::class, [
                'label' => 'Dónde se guarda',
                'required' => false,
                'help' => 'Para que quien lo reserve sepa adónde ir: «conserjería», «armario del aula 12».',
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Qué hay que saber',
                'required' => false,
                'help' => 'Lo que se olvida siempre: «devolverlo cargado», «pedir la llave en conserjería».',
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'En uso',
                'required' => false,
                'help' => 'Al desmarcarlo deja de poder reservarse, pero las reservas ya hechas se conservan.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Material::class]);
    }
}
