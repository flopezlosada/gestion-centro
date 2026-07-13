<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Role;
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
 * Create/edit form for a task, kept intentionally light. The lifecycle (simple vs deliverable) is not
 * a field the user picks: it is derived by the controller from the single "carries a deliverable"
 * toggle, and whether a task closes with a checkbox is app behaviour, not a per-task choice. The
 * "assign to" field appears only when the creator actually commands others ({@see $options['include_assignee']});
 * a plain member has no picker and the task is theirs. The responsible-role field is leadership-only
 * ({@see $options['include_role']}); the deliverable toggle only on creation ({@see $options['include_deliverable']}),
 * since the lifecycle cannot change once running.
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

        // Only offered when the creator commands others; a plain member's task is simply theirs.
        if (true === $options['include_assignee']) {
            $builder->add('assignedUser', EntityType::class, [
                'label' => 'Responsable',
                'class' => User::class,
                'choices' => $options['assignable_users'],
                'choice_label' => 'fullName',
            ]);
        }

        // The responsible role is a structural, leadership-only field: it appears only when the
        // controller allows it (direction / head of studies). For everyone else it is absent, so a
        // routine edit can never alter it.
        if (true === $options['include_role']) {
            $builder->add('assignedRole', EntityType::class, [
                'label' => 'Rol responsable',
                'class' => Role::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => '— Sin rol —',
                'help' => 'La función que responde de la tarea (además de la persona asignada).',
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
            'include_role' => false,
            'include_assignee' => false,
            'include_deliverable' => true,
        ]);
        $resolver->setAllowedTypes('assignable_users', 'array');
        $resolver->setAllowedTypes('include_role', 'bool');
        $resolver->setAllowedTypes('include_assignee', 'bool');
        $resolver->setAllowedTypes('include_deliverable', 'bool');
    }
}
