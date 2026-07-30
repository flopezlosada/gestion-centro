<?php

declare(strict_types=1);

namespace App\Form;

use App\Support\DocumentUpload;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form to place an order with the copy room, over {@see CopyRequestFormData}.
 *
 * One form, two shapes. Ordering from a guardia only asks how many copies and any instructions: what
 * to print and what it is for are already known. A standalone order ({@code standalone: true}) also
 * asks for the document and for a one-line description, because nothing else can supply them.
 *
 * The number of copies is always required — an order that does not say how many is useless to whoever
 * stands at the photocopier, which was the centre's own open question.
 *
 * @extends AbstractType<CopyRequestFormData>
 */
final class CopyRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if (true === $options['standalone']) {
            $builder
                ->add('context', TextType::class, [
                    'label' => '¿Para qué son?',
                    'help' => 'Una línea que conserjería entienda sin preguntar: «Examen de 2º ESO B, jueves».',
                ])
                ->add('document', FileType::class, [
                    'label' => 'Documento a fotocopiar',
                    'constraints' => [new Assert\NotNull(message: 'Adjunta el documento que hay que fotocopiar.')],
                    'help' => sprintf('PDF, Office, texto o imagen. Máximo %d MB.', intdiv(DocumentUpload::MAX_BYTES, 1024 * 1024)),
                ]);
        }

        $builder
            ->add('copies', IntegerType::class, [
                'label' => 'Número de copias',
                'attr' => ['min' => 1, 'max' => 500, 'inputmode' => 'numeric'],
                'help' => 'Obligatorio: sin el número de copias el encargo no se puede preparar.',
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Indicaciones',
                'required' => false,
                'help' => 'A doble cara, grapado, para qué hora hacen falta…',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CopyRequestFormData::class,
            'standalone' => false,
        ]);
        $resolver->setAllowedTypes('standalone', 'bool');
    }
}
