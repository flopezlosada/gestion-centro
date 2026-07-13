<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\CargoResponsibility;
use App\Entity\PersonResponsibility;
use App\Entity\Role;
use App\Entity\RoleResponsibility;
use App\Entity\Task;
use App\Entity\Unit;
use App\Entity\User;
use App\Enum\ResponsibilityMode;
use App\Enum\TaskType;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Form-backing object for creating/editing a {@see Task}. A DTO on purpose: Task's constructor
 * requires title/schoolYear/dueDate/type, which does not map cleanly onto a form, so the controller
 * builds/updates the Task from this validated data instead.
 *
 * The responsibility is chosen as one of three modes ({@see ResponsibilityMode}); only the field for
 * the chosen mode is required ({@see validateResponsibility()}), which the controller turns into the
 * matching {@see \App\Entity\TaskResponsibility}.
 */
final class TaskFormData
{
    #[Assert\NotBlank(message: 'El título es obligatorio.')]
    #[Assert\Length(max: 200)]
    public string $title = '';

    public ?string $description = null;

    public TaskType $type = TaskType::SIMPLE;

    #[Assert\NotNull(message: 'Pon una fecha límite.')]
    public ?\DateTimeImmutable $dueDate = null;

    /** How the responsibility is chosen; defaults to a specific person (the everyone-can option). */
    public ResponsibilityMode $responsibilityMode = ResponsibilityMode::PERSON;

    /** The department whose head is responsible (cargo mode). */
    public ?Unit $responsibilityUnit = null;

    /** The specific person responsible (person mode). */
    public ?User $assignedUser = null;

    /** The role whose holders are responsible (role mode). */
    public ?Role $responsibilityRole = null;

    public bool $mandatory = true;

    public bool $requiresCheckbox = true;

    public bool $requiresDocument = false;

    /**
     * Only the field for the chosen mode must be filled in — the cross-field rule a single-field
     * constraint cannot express.
     *
     * @param ExecutionContextInterface $context the validation context to attach violations to
     */
    #[Assert\Callback]
    public function validateResponsibility(ExecutionContextInterface $context): void
    {
        [$path, $missing] = match ($this->responsibilityMode) {
            ResponsibilityMode::CARGO => ['responsibilityUnit', null === $this->responsibilityUnit],
            ResponsibilityMode::PERSON => ['assignedUser', null === $this->assignedUser],
            ResponsibilityMode::ROLE => ['responsibilityRole', null === $this->responsibilityRole],
        };

        if ($missing) {
            $context->buildViolation('Elige quién responde de la tarea.')->atPath($path)->addViolation();
        }
    }

    /**
     * Prefills the form data from an existing task (for editing), deriving the responsibility mode from
     * whatever shape the task currently carries (falling back to its legacy assignee).
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
        $data->type = $task->getType();
        $data->dueDate = $task->getDueDate();
        $data->mandatory = $task->isMandatory();
        $data->requiresCheckbox = $task->requiresCheckbox();
        $data->requiresDocument = $task->requiresDocument();

        $responsibility = $task->getResponsibility();
        if ($responsibility instanceof CargoResponsibility) {
            $data->responsibilityMode = ResponsibilityMode::CARGO;
            $data->responsibilityUnit = $responsibility->getUnit();
        } elseif ($responsibility instanceof RoleResponsibility) {
            $data->responsibilityMode = ResponsibilityMode::ROLE;
            $data->responsibilityRole = $responsibility->getRole();
        } elseif ($responsibility instanceof PersonResponsibility) {
            $data->responsibilityMode = ResponsibilityMode::PERSON;
            $data->assignedUser = $responsibility->getUser();
        } else {
            $data->responsibilityMode = ResponsibilityMode::PERSON;
            $data->assignedUser = $task->getAssignedUser();
        }

        return $data;
    }
}
