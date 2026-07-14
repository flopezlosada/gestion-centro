<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Role;
use App\Entity\Task;
use App\Entity\TaskResponsibility;
use App\Entity\Department;
use App\Entity\User;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Form-backing object for creating/editing a {@see Task}. The responsibility is chosen as a cascade:
 * a role, then — only when the role is per-department ({@see Role::isPerDepartment()}) — its
 * department, and finally the concrete person, picked among those who actually hold that role in that
 * department (the choice is coupled: someone who does not hold it cannot be selected). The controller
 * turns the role + department into a {@see TaskResponsibility} (the structural backbone) and stores the
 * chosen person as the task's assignee.
 */
final class TaskFormData
{
    #[Assert\NotBlank(message: 'El título es obligatorio.')]
    #[Assert\Length(max: 200)]
    public string $title = '';

    public ?string $description = null;

    #[Assert\NotNull(message: 'Pon una fecha límite.')]
    public ?\DateTimeImmutable $dueDate = null;

    /** The responsible role (first step of the cascade). */
    #[Assert\NotNull(message: 'Elige el rol responsable.')]
    public ?Role $responsibilityRole = null;

    /** The department (second step); required only when the role is per-department. */
    public ?Department $responsibilityUnit = null;

    /** The concrete responsible person (third step), one of the role + department holders. */
    public ?User $responsibilityUser = null;

    public bool $mandatory = true;

    public bool $requiresCheckbox = true;

    public bool $requiresDocument = false;

    /**
     * Validates the responsibility cascade end to end: a per-department role needs a department (and a
     * centre-wide one must not carry one — enforced by the controller), and the chosen person must
     * actually hold that role in that department. These are cross-field rules a single-field constraint
     * cannot express.
     *
     * @param ExecutionContextInterface $context the validation context to attach violations to
     */
    #[Assert\Callback]
    public function validateResponsibility(ExecutionContextInterface $context): void
    {
        if (null === $this->responsibilityRole) {
            return; // the NotNull on the role already reports the empty case
        }

        $perDepartment = $this->responsibilityRole->isPerDepartment();
        if ($perDepartment && null === $this->responsibilityUnit) {
            $context->buildViolation('Elige el departamento.')->atPath('responsibilityUnit')->addViolation();

            return; // without the department the holder set is undefined
        }

        if (null === $this->responsibilityUser) {
            $context->buildViolation('Elige la persona responsable.')->atPath('responsibilityUser')->addViolation();

            return;
        }

        // Coupled choice: the person must be one of the current holders of role + (department).
        $unit = $perDepartment ? $this->responsibilityUnit : null;
        if (!(new TaskResponsibility($this->responsibilityRole, $unit))->isHeldBy($this->responsibilityUser)) {
            $context->buildViolation('Esa persona no tiene ese rol en ese departamento.')->atPath('responsibilityUser')->addViolation();
        }
    }

    /**
     * Prefills the form data from an existing task (for editing).
     *
     * @param Task $task the task to edit
     *
     * @return self the prefilled form data
     */
    public static function fromTask(Task $task): self
    {
        $data = new self();
        $data->title = $task->getTitle();
        $data->description = $task->getDescription();
        $data->dueDate = $task->getDueDate();
        $data->mandatory = $task->isMandatory();
        $data->requiresCheckbox = $task->requiresCheckbox();
        $data->requiresDocument = $task->requiresDocument();

        $responsibility = $task->getResponsibility();
        if (null !== $responsibility) {
            $data->responsibilityRole = $responsibility->getRole();
            $data->responsibilityUnit = $responsibility->getUnit();
        }
        $data->responsibilityUser = $task->getAssignedUser();

        return $data;
    }
}
