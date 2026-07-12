<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AcademicYear;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Admin form to set/edit the dated structure of one school year: the course code and the start and
 * end of each of the three terms. The strict ordering of the six dates is validated on the entity
 * ({@see AcademicYear::validateTermOrder()}).
 *
 * @extends AbstractType<AcademicYear>
 */
final class AcademicYearType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $date = static fn (string $label, string $help): array => [
            'label' => $label,
            'help' => $help,
            'widget' => 'single_text',
            'input' => 'datetime_immutable',
        ];

        $builder
            ->add('schoolYear', TextType::class, [
                'label' => 'Curso',
                'help' => 'Formato "2026-2027".',
                'attr' => ['placeholder' => '2026-2027'],
            ])
            ->add('term1Start', DateType::class, $date('Inicio del 1.er trimestre', 'Primer día lectivo del curso.'))
            ->add('term1End', DateType::class, $date('Fin del 1.er trimestre', 'Último día lectivo antes de las vacaciones de Navidad.'))
            ->add('term2Start', DateType::class, $date('Inicio del 2.º trimestre', 'Primer día lectivo tras las vacaciones de Navidad.'))
            ->add('term2End', DateType::class, $date('Fin del 2.º trimestre', 'Último día lectivo antes de las vacaciones de Semana Santa.'))
            ->add('term3Start', DateType::class, $date('Inicio del 3.er trimestre', 'Primer día lectivo tras las vacaciones de Semana Santa.'))
            ->add('term3End', DateType::class, $date('Fin del 3.er trimestre', 'Último día lectivo del curso.'));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AcademicYear::class,
        ]);
    }
}
