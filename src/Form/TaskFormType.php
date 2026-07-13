<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Role;
use App\Entity\Unit;
use App\Entity\User;
use App\Enum\ResponsibilityMode;
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
 * Create/edit form for a task. The responsibility is chosen by mode ({@see ResponsibilityMode}): a
 * plain creator only ever assigns a person (no mode selector); leadership
 * ({@see $options['include_structural']}) additionally gets "cargo" (a department's head, which
 * follows the post) and "rol". The lifecycle (simple vs deliverable) is derived by the controller
 * from the single deliverable toggle, shown only on creation ({@see $options['include_deliverable']}).
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
            ->add('mandatory', CheckboxType::class, [
                'label' => 'Obligatoria',
                'required' => false,
                'help' => 'Las obligatorias cuentan como pendientes hasta cerrarse; las voluntarias son opcionales.',
            ]);

        // Leadership can target a post or a role, not just a person; a plain creator only sees the
        // person picker, so its responsibility is always a person (the default mode).
        if (true === $options['include_structural']) {
            $builder->add('responsibilityMode', EnumType::class, [
                'label' => 'Responsable',
                'class' => ResponsibilityMode::class,
                'choice_label' => static fn (ResponsibilityMode $mode): string => $mode->label(),
                'expanded' => true,
                'row_attr' => ['class' => 'resp-mode'],
            ]);
            $builder->add('responsibilityUnit', EntityType::class, [
                'label' => 'Departamento (su jefatura)',
                'class' => Unit::class,
                'choices' => $options['assignable_units'],
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => '— Elige departamento —',
                'help' => 'Responsable = quien sea la jefatura de ese departamento en cada momento.',
                'row_attr' => ['data-resp-mode' => 'cargo'],
            ]);
            $builder->add('responsibilityRole', EntityType::class, [
                'label' => 'Rol',
                'class' => Role::class,
                'choices' => $options['assignable_roles'],
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => '— Elige rol —',
                'row_attr' => ['data-resp-mode' => 'role'],
            ]);
        }

        // The person picker: shown to leadership (for person mode) and to any creator who commands
        // others. A lone creator gets no picker — the controller assigns the task to themselves.
        if (true === $options['include_structural'] || true === $options['include_assignee']) {
            $builder->add('assignedUser', EntityType::class, [
                'label' => 'Persona',
                'class' => User::class,
                'choices' => $options['assignable_users'],
                'choice_label' => 'fullName',
                'required' => false,
                'placeholder' => '— Elige persona —',
                'row_attr' => ['data-resp-mode' => 'person'],
            ]);
        }

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
            'assignable_users' => [],
            'assignable_units' => [],
            'assignable_roles' => [],
            'include_structural' => false,
            'include_assignee' => false,
            'include_deliverable' => true,
        ]);
        $resolver->setAllowedTypes('assignable_users', 'array');
        $resolver->setAllowedTypes('assignable_units', 'array');
        $resolver->setAllowedTypes('assignable_roles', 'array');
        $resolver->setAllowedTypes('include_structural', 'bool');
        $resolver->setAllowedTypes('include_assignee', 'bool');
        $resolver->setAllowedTypes('include_deliverable', 'bool');
    }
}
