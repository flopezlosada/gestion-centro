<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\FamilyVolunteer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Alta y edición de la cuenta de una familia que colabora en las actividades.
 *
 * `empty_data` vacío en los dos campos: un campo en blanco llega como cadena vacía y lo rechaza la
 * validación con su mensaje, en vez de reventar el setter, que no admite null.
 *
 * @extends AbstractType<FamilyVolunteer>
 */
final class FamilyVolunteerFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('fullName', TextType::class, [
                'label' => 'Nombre',
                'empty_data' => '',
                'help' => 'Como la conoce el centro: «Marta, madre de Lucía (2º B)».',
            ])
            ->add('email', EmailType::class, [
                'label' => 'Correo electrónico',
                'empty_data' => '',
                'help' => 'Con el que entrará: le llega ahí un enlace de acceso. Puede ser de cualquier proveedor.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => FamilyVolunteer::class]);
    }
}
