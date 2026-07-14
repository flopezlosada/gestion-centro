<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Role;
use App\Entity\Department;
use App\Entity\User;
use App\Service\SchoolCalendar;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
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
                'label' => 'Departamento',
                'class' => Department::class,
                'choices' => $options['assignable_units'],
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => '— Elige departamento —',
                'help' => 'Solo los departamentos: acota a quién tiene el rol dentro de él.',
                'row_attr' => ['data-dept-step' => '1'],
            ])
            ->add('responsibilityUser', EntityType::class, [
                'label' => 'Persona responsable',
                'class' => User::class,
                'choices' => $options['assignable_users'],
                'choice_label' => 'fullName',
                'placeholder' => '— Elige la persona —',
                'help' => 'Solo quienes tienen ese rol en ese departamento. Luego un superior puede reasignarla.',
                // task-form.js filters this list by the chosen role + department using these attributes.
                'choice_attr' => static fn (User $candidate): array => [
                    'data-roles' => implode(' ', $candidate->getAssignedRoles()->map(static fn (Role $role): int => (int) $role->getId())->toArray()),
                    'data-unit' => (string) ($candidate->getUnit()?->getId() ?? ''),
                ],
                'row_attr' => ['data-resp-user-step' => '1'],
            ])
            ->add('mandatory', CheckboxType::class, [
                'label' => 'Obligatoria',
                'required' => false,
                'help' => 'Las obligatorias cuentan como pendientes hasta cerrarse; las voluntarias son opcionales.',
            ]);

        // The single deliverable switch also decides the lifecycle (see the controller). Only on
        // creation: the lifecycle cannot change once the task is running.
        if (true === $options['include_deliverable']) {
            $builder->add('requiresDocument', CheckboxType::class, [
                'label' => 'Lleva entregable',
                'required' => false,
                'help' => 'Pide una referencia a un documento (un enlace, nunca el archivo) y añade un paso de entrega y validación.',
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
        ]);
        $resolver->setAllowedTypes('assignable_roles', 'array');
        $resolver->setAllowedTypes('assignable_units', 'array');
        $resolver->setAllowedTypes('assignable_users', 'array');
        $resolver->setAllowedTypes('include_deliverable', 'bool');
    }
}
