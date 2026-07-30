<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Department;
use App\Enum\EducationLevel;
use App\Repository\DepartmentRepository;
use App\Support\DocumentUpload;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form to add or edit a task in the guardia task bank, over {@see GuardiaTaskBankFormData}.
 *
 * The subject is a closed list ({@code subjects}) fed from the course's own timetable: a bank task has
 * to be of the subject the class was going to have, and free text would never match it. "Quitar el
 * documento" is only offered when there is one to remove ({@code has_document}).
 *
 * @extends AbstractType<GuardiaTaskBankFormData>
 */
final class GuardiaTaskBankItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('level', EnumType::class, [
                'class' => EducationLevel::class,
                'label' => 'Nivel',
                'choices' => EducationLevel::inDisplayOrder(),
                'choice_label' => static fn (EducationLevel $l): string => $l->label(),
                'placeholder' => '— Elige el nivel —',
                'help' => 'Para qué curso sirve la tarea. Es el filtro con el que la buscará quien esté de guardia.',
            ])
            ->add('subject', ChoiceType::class, [
                'label' => 'Materia',
                'choices' => array_combine($options['subjects'], $options['subjects']),
                'placeholder' => [] === $options['subjects'] ? '— No hay horario importado —' : '— Elige la materia —',
                'disabled' => [] === $options['subjects'],
                'help' => 'Las materias que de verdad se dan este curso, tal como vienen del horario: así la tarea casa con la clase que se pierde.',
            ])
            ->add('sections', TextType::class, [
                'label' => 'Solo para los grupos (letras)',
                'required' => false,
                'attr' => ['placeholder' => 'A, C'],
                'help' => 'Opcional. Déjalo vacío si vale para cualquier grupo del nivel; en optativas suele quedarse vacío.',
            ])
            ->add('department', EntityType::class, [
                'class' => Department::class,
                'choice_label' => 'name',
                'label' => 'Departamento',
                'placeholder' => '— Elige el departamento —',
                'query_builder' => static fn (DepartmentRepository $repo) => $repo->createQueryBuilder('d')
                    ->where('d.active = true')
                    ->orderBy('d.name', 'ASC'),
                'help' => 'Quién aporta y mantiene la tarea.',
            ])
            ->add('title', TextType::class, [
                'label' => 'Título',
                'help' => 'Lo que se lee en la lista: «Comprensión lectora — El Quijote», «Ficha de fracciones».',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Instrucciones para el grupo',
                'required' => false,
                'help' => 'Lo que hay que hacer, si no viene todo en el documento.',
            ])
            ->add('document', FileType::class, [
                'label' => 'Documento',
                'required' => false,
                'help' => sprintf('PDF, Office, texto o imagen. Máximo %d MB.', intdiv(DocumentUpload::MAX_BYTES, 1024 * 1024)),
            ])
            ->add('suggestedCopies', IntegerType::class, [
                'label' => 'Copias que suele necesitar',
                'required' => false,
                'attr' => ['min' => 1, 'max' => 500],
                'help' => 'Se propone al pedir fotocopias; siempre se puede ajustar. Déjalo vacío si no aplica.',
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'Disponible para las guardias',
                'required' => false,
                'help' => 'Al retirarla deja de salir en el reparto, pero se conserva en las guardias que ya la usaron.',
            ]);

        if (true === $options['has_document']) {
            $builder->add('removeDocument', CheckboxType::class, [
                'label' => 'Quitar el documento actual',
                'required' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => GuardiaTaskBankFormData::class,
            'has_document' => false,
            'subjects' => [],
        ]);
        $resolver->setAllowedTypes('has_document', 'bool');
        $resolver->setAllowedTypes('subjects', 'string[]');
    }
}
