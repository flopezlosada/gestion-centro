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
 * to be of the subject the class was going to have, and free text would never match it. A task may
 * carry several documents; the form only picks them on creation ({@code with_documents}), since an
 * existing task has its own documents block that uploads and removes them at once.
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
            ->add('suggestedCopies', IntegerType::class, [
                'label' => 'Copias que suele necesitar',
                'required' => false,
                'attr' => ['min' => 1, 'max' => 500],
                'help' => 'Se propone para cada documento al pedir fotocopias, y ahí se ajusta el de cada uno (el solucionario, 1). Déjalo vacío si no aplica.',
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'Disponible para las guardias',
                'required' => false,
                'help' => 'Al retirarla deja de salir en el reparto, pero se conserva en las guardias que ya la usaron.',
            ]);

        if ($options['with_documents']) {
            $builder->add('documents', FileType::class, [
                'label' => 'Documentos',
                'multiple' => true,
                'required' => false,
                'help' => sprintf('Puedes elegir varios a la vez: la ficha, el solucionario… PDF, Office, texto o imagen; máximo %d MB cada uno.', intdiv(DocumentUpload::MAX_BYTES, 1024 * 1024)),
                // Avisa del tamaño antes de enviar (file-size-guard.js), con el mismo límite que aplica el
                // servidor a cada fichero y el del envío entero.
                'attr' => ['data-max-bytes' => DocumentUpload::MAX_BYTES, 'data-max-total-bytes' => DocumentUpload::MAX_REQUEST_BYTES],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => GuardiaTaskBankFormData::class,
            // Solo al crearla: después los documentos tienen su propio bloque, que sube y quita al momento.
            'with_documents' => false,
            'subjects' => [],
        ]);
        $resolver->setAllowedTypes('with_documents', 'bool');
        $resolver->setAllowedTypes('subjects', 'string[]');
    }
}
