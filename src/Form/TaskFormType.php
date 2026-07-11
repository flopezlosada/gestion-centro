<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use App\Enum\TaskType;
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
 * Create/edit form for a task. The "assign to" choices are scoped by the controller to the people
 * the creator may assign to (themselves + their subordinates). The task type is only editable on
 * creation ({@see $options['include_type']}), since changing it mid-lifecycle would break the state.
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
            ->add('assignedUser', EntityType::class, [
                'label' => 'Responsable',
                'class' => User::class,
                'choices' => $options['assignable_users'],
                'choice_label' => 'fullName',
            ])
            ->add('mandatory', CheckboxType::class, ['label' => 'Obligatoria', 'required' => false])
            ->add('requiresCheckbox', CheckboxType::class, ['label' => 'Se marca hecha con una casilla', 'required' => false])
            ->add('requiresDocument', CheckboxType::class, ['label' => 'Lleva entregable (documento)', 'required' => false]);

        if (true === $options['include_type']) {
            $builder->add('type', EnumType::class, [
                'label' => 'Tipo de tarea',
                'class' => TaskType::class,
                'choice_label' => static fn (TaskType $type): string => $type->label(),
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
            'include_type' => true,
        ]);
        $resolver->setAllowedTypes('assignable_users', 'array');
        $resolver->setAllowedTypes('include_type', 'bool');
    }
}
