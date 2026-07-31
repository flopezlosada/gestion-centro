<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\MeetingType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Alta y edición de un tipo de reunión. Tres campos y ninguno más: el tipo solo pone nombre y ordena el
 * archivo, así que todo lo que se le añada aquí es algo que alguien tendrá que rellenar sin saber para
 * qué sirve.
 *
 * @extends AbstractType<MeetingType>
 */
final class MeetingTypeFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nombre',
                'help' => 'Como se dice en el centro: «Comisión de Coordinación Pedagógica (CCP)», «Comisión de biblioteca»…',
            ])
            ->add('minutesApprovalRequired', CheckboxType::class, [
                'label' => 'El acta se aprueba en la reunión siguiente',
                'required' => false,
                'help' => 'Solo marca el valor por defecto al convocar; cada reunión puede cambiarlo.',
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'En uso',
                'required' => false,
                'help' => 'Al desmarcarlo deja de ofrecerse al convocar, pero las actas ya archivadas conservan su tipo.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => MeetingType::class]);
    }
}
