<?php

declare(strict_types=1);

namespace App\Form;

use App\Support\DocumentUpload;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
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
 * The number of copies is always required, one per file when there are several — an order that does not say how many is useless to whoever
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
                    'help' => sprintf('Solo PDF. Máximo %d MB. Conserjería lo recibe por correo; aquí se borra al pasar el día. Si no hace falta, que no lleve nombres ni datos del alumnado.', intdiv(DocumentUpload::MAX_BYTES, 1024 * 1024)),
                    // `accept` solo filtra el selector de archivos (quien manda es DocumentUpload::pdfProblem());
                    // `data-max-bytes` avisa del tamaño antes de enviar (file-size-guard.js): un PDF escaneado
                    // pasa fácil del límite, y por encima de ~15 MB el servidor corta sin llegar a la app.
                    'attr' => ['accept' => '.pdf,application/pdf', 'data-max-bytes' => DocumentUpload::MAX_BYTES],
                ]);
        }

        // Una casilla por archivo, rotulada con su nombre: cada uno lleva sus copias. Sin archivos (o en un
        // encargo suelto), una sola, «Número de copias».
        $copies = $builder->create('copies', FormType::class, ['label' => false]);
        $files = $options['file_names'];
        foreach ([] !== $files ? $files : [null] as $i => $name) {
            $copies->add((string) $i, IntegerType::class, [
                'label' => null !== $name ? sprintf('Copias de «%s»', $name) : 'Número de copias',
                'attr' => ['min' => 1, 'max' => 500, 'inputmode' => 'numeric'],
                'help' => 0 === $i ? 'Obligatorio: sin el número de copias el encargo no se puede preparar.' : null,
            ]);
        }

        $builder
            // Huella de los archivos con los que se pintó el formulario: el controlador la compara al enviar.
            ->add('files', HiddenType::class, ['mapped' => false, 'data' => $options['files_signature']])
            ->add($copies)
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
            // Los nombres de los archivos a imprimir, en orden: uno por casilla de copias.
            'file_names' => [],
            'files_signature' => '',
        ]);
        $resolver->setAllowedTypes('files_signature', 'string');
        $resolver->setAllowedTypes('standalone', 'bool');
        $resolver->setAllowedTypes('file_names', 'string[]');
    }
}
