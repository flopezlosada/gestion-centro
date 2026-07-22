<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AcademicYear;
use App\Repository\AcademicYearRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * Admin self-service form to import a Peñalara GHC timetable: the target course plus the two exports
 * (the planificador name dictionary and the resolved Horario). Not backed by an entity — its data is
 * handed straight to {@see \App\Guardia\TimetableImporter}.
 *
 * The files are validated here (present, size, XML-ish) and parsed in memory by the importer; they
 * are not stored, since nothing reads them back — the imported {@see \App\Entity\ScheduleEntry} rows
 * are the durable result.
 *
 * @extends AbstractType<array{academicYear: AcademicYear, planificador: \Symfony\Component\HttpFoundation\File\UploadedFile, horario: \Symfony\Component\HttpFoundation\File\UploadedFile}>
 */
final class TimetableImportType extends AbstractType
{
    /** Peñalara XML exports are ~1–2 MB; leave generous headroom without inviting arbitrary uploads. */
    private const string MAX_SIZE = '8M';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Only the size is constrained here; whether the contents are a valid Peñalara XML is decided
        // by the parser, which returns a clear error the controller surfaces as a flash. A MIME-type
        // allowlist is deliberately avoided: finfo can label a valid export unexpectedly and reject it.
        $file = fn (string $label, string $help): array => [
            'label' => $label,
            'help' => $help,
            'mapped' => true,
            'constraints' => [
                new NotNull(message: 'Adjunta el fichero.'),
                new File(maxSize: self::MAX_SIZE),
            ],
        ];

        $builder
            ->add('academicYear', EntityType::class, [
                'label' => 'Curso',
                'help' => 'Curso al que pertenece este horario. Si no aparece, créalo primero en "Años escolares".',
                'class' => AcademicYear::class,
                'choice_label' => 'schoolYear',
                'query_builder' => static fn (AcademicYearRepository $r) => $r->createQueryBuilder('a')->orderBy('a.schoolYear', 'DESC'),
                'placeholder' => 'Elige un curso…',
                'constraints' => [new NotNull(message: 'Elige el curso destino.')],
            ])
            ->add('planificador', FileType::class, $file('planificador.xml', 'Diccionario de nombres (menú Peñalara: exportar planificador).'))
            ->add('horario', FileType::class, $file('Horario.xml', 'Horario resuelto (Editor → transferir horario a → otras aplicaciones externas).'));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}
