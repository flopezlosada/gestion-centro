<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Department;
use App\Entity\Role;
use App\Entity\User;
use App\Enum\DeliverableRequirement;
use App\Service\SchoolCalendar;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Create/edit form for a task. The responsibility is a cascade: pick a role, then — only if the role
 * is per-department — the department, and finally the concrete person. Each role option carries
 * data-per-department so task-form.js can show the department step only when it applies; each person
 * option carries data-roles/data-unit so the JS narrows the person list to those who hold the chosen
 * role in the chosen department (a coupled choice). The lifecycle is derived by the controller from the
 * single deliverable toggle, shown only on creation ({@see $options['include_deliverable']}).
 *
 * @extends AbstractType<TaskFormData>
 */
final class TaskFormType extends AbstractType
{
    public function __construct(private readonly SchoolCalendar $schoolCalendar)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['label' => 'Título'])
            ->add('description', TextareaType::class, ['label' => 'Descripción', 'required' => false])
            ->add('dueDate', DateType::class, [
                'label' => 'Fecha límite',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'help' => 'Debe ser un día lectivo: ni fin de semana ni día no lectivo.',
                'constraints' => [new Assert\Callback($this->validateLectiveDeadline(...))],
            ])
            ->add('responsibilityRole', EntityType::class, [
                'label' => 'Rol responsable',
                'class' => Role::class,
                'choices' => $options['assignable_roles'],
                'choice_label' => 'name',
                'placeholder' => '— Elige rol —',
                // Marks which roles need a department, so the JS shows/hides the department step.
                'choice_attr' => static fn (Role $role): array => ['data-per-department' => $role->isPerDepartment() ? '1' : '0'],
            ])
            ->add('responsibilityUnit', EntityType::class, [
                'class' => Department::class,
                'choices' => $options['assignable_units'],
                'choice_label' => 'name',
                'required' => false,
                // Al crear, dejarlo vacío es lo que significa "de todos los departamentos": es la forma
                // de mandar la misma tarea a todo el claustro sin repetir el formulario quince veces.
                'label' => 'Departamento',
                'placeholder' => $options['multiple_assignees'] ? '— Todos los departamentos —' : '— Elige departamento —',
                'help' => $options['multiple_assignees']
                    ? 'Acota a quién tiene el rol dentro de él. Déjalo vacío para llegar a todos los departamentos.'
                    : 'Solo los departamentos: acota a quién tiene el rol dentro de él.',
                'row_attr' => ['data-dept-step' => '1'],
            ])
            ->add('mandatory', CheckboxType::class, [
                'label' => 'Obligatoria',
                'required' => false,
                'help' => 'Las obligatorias cuentan como pendientes hasta cerrarse; las voluntarias son opcionales.',
            ]);

        // Tercer paso de la cascada. Al CREAR es una lista (una tarea por persona: "a un solo usuario o a
        // un colectivo"); al EDITAR es la única persona que tiene esa tarea. Mismos atributos en las
        // opciones en los dos casos, porque el mismo task-form.js acota las dos.
        $candidateAttributes = static fn (User $candidate): array => [
            'data-roles' => implode(' ', $candidate->getAssignedRoles()->map(static fn (Role $role): int => (int) $role->getId())->toArray()),
            'data-unit' => (string) ($candidate->getUnit()?->getId() ?? ''),
        ];

        if (true === $options['multiple_assignees']) {
            $builder->add('responsibilityUsers', EntityType::class, [
                'label' => '¿Para quién?',
                'class' => User::class,
                'choices' => $options['assignable_users'],
                'choice_label' => 'fullName',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'help' => 'Marca a una persona o a varias. Cada una recibe su propia tarea y la entrega por su cuenta.',
                'choice_attr' => $candidateAttributes,
                'row_attr' => ['data-resp-user-step' => '1'],
            ]);
        } else {
            $builder->add('responsibilityUser', EntityType::class, [
                'label' => 'Persona responsable',
                'class' => User::class,
                'choices' => $options['assignable_users'],
                'choice_label' => 'fullName',
                'placeholder' => '— Elige la persona —',
                'help' => 'Solo quienes tienen ese rol en ese departamento. Luego un superior puede reasignarla.',
                'choice_attr' => $candidateAttributes,
                'row_attr' => ['data-resp-user-step' => '1'],
            ]);
        }

        // Qué hay que entregar. Solo al crear: cambiarlo con la tarea en marcha dejaría a alguien con un
        // enlace ya entregado y un cartel pidiéndole un archivo.
        if (true === $options['include_deliverable']) {
            $builder->add('deliverable', EnumType::class, [
                'class' => DeliverableRequirement::class,
                'label' => '¿Qué hay que entregar?',
                'expanded' => true,
                'choice_label' => static fn (DeliverableRequirement $r): string => $r->label(),
                'help' => 'Con enlace o archivo, la tarea pasa por un paso de entrega y revisión antes de darse por finalizada.',
            ]);
        }
    }

    /**
     * Rejects a deadline that does not fall on a teaching day (a weekend or a registered non-teaching
     * day). Runs on both creation and edit, so a task can never be saved with a non-teaching deadline.
     * The null case is left to the field's own {@see Assert\NotNull}.
     *
     * @param \DateTimeImmutable|null   $dueDate the submitted deadline
     * @param ExecutionContextInterface $context the validation context to attach the violation to
     */
    public function validateLectiveDeadline(?\DateTimeImmutable $dueDate, ExecutionContextInterface $context): void
    {
        if (null !== $dueDate && !$this->schoolCalendar->isLective($dueDate)) {
            $context->buildViolation('La fecha límite debe ser un día lectivo: no puede caer en fin de semana ni en un día no lectivo.')
                ->addViolation();
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TaskFormData::class,
            'assignable_roles' => [],
            'assignable_units' => [],
            'assignable_users' => [],
            'include_deliverable' => true,
            'multiple_assignees' => false,
        ]);
        $resolver->setAllowedTypes('assignable_roles', 'array');
        $resolver->setAllowedTypes('assignable_units', 'array');
        $resolver->setAllowedTypes('assignable_users', 'array');
        $resolver->setAllowedTypes('include_deliverable', 'bool');
        $resolver->setAllowedTypes('multiple_assignees', 'bool');
    }
}
