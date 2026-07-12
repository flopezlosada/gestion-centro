<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Unit;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Admin form to create/edit a department ({@see Unit}): its code, name, description and whether it is
 * active. Its head (jefatura) and its people are managed from the department's own page, not here.
 *
 * @extends AbstractType<Unit>
 */
final class UnitType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Código',
                'help' => 'Identificador corto y estable, p. ej. "matematicas".',
            ])
            ->add('name', TextType::class, ['label' => 'Nombre'])
            ->add('description', TextareaType::class, ['label' => 'Descripción', 'required' => false])
            ->add('active', CheckboxType::class, [
                'label' => 'Activo (se usa para nuevas asignaciones)',
                'required' => false,
                'help' => 'Al desactivarlo se conserva el histórico, pero deja de usarse para asignar tareas nuevas.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Unit::class,
        ]);
    }
}
