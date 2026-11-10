<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One file of a copy order, over {@see CopyRequestLineData}: how many copies and, when there is more
 * than one file to choose from, whether to print it at all.
 *
 * @extends AbstractType<CopyRequestLineData>
 */
final class CopyRequestLineType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $name = $options['file_name'];

        // Con un solo archivo no hay nada que elegir: desmarcarlo dejaría el encargo vacío.
        if (true === $options['choosable']) {
            $builder->add('print', CheckboxType::class, [
                'label' => null !== $name ? sprintf('Imprimir «%s»', $name) : 'Imprimir',
                'required' => false,
            ]);
        }

        $builder->add('copies', IntegerType::class, [
            'label' => null !== $name ? sprintf('Copias de «%s»', $name) : 'Número de copias',
            // Obligatorio salvo que el archivo se pueda dejar fuera: entonces lo valida CopyRequestLineData.
            'required' => !$options['choosable'],
            'help' => $options['copies_help'],
            'attr' => ['min' => 1, 'max' => CopyRequestLineData::MAX_COPIES, 'inputmode' => 'numeric'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CopyRequestLineData::class,
            'label' => false,
            'file_name' => null,
            'choosable' => false,
            // La ayuda de la casilla de copias (la del grupo entero va en la primera fila).
            'copies_help' => null,
        ]);
        $resolver->setAllowedTypes('file_name', ['null', 'string']);
        $resolver->setAllowedTypes('copies_help', ['null', 'string']);
        $resolver->setAllowedTypes('choosable', 'bool');
    }
}
