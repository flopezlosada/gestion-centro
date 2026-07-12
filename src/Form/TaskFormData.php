<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Role;
use App\Entity\Task;
use App\Entity\User;
use App\Enum\TaskType;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form-backing object for creating/editing a {@see Task}. A DTO on purpose: Task's constructor
 * requires title/schoolYear/dueDate/type, which does not map cleanly onto a form, so the controller
 * builds/updates the Task from this validated data instead.
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

    #[Assert\NotNull(message: 'Elige a quién se asigna la tarea.')]
    public ?User $assignedUser = null;

    /**
     * The responsible role (the structural function that owns the task, e.g. head of department).
     * Coexists with {@see $assignedUser}; only leadership may change it, so the field is present in
     * the form only for them (see {@see TaskFormType} `include_role`).
     */
    public ?Role $assignedRole = null;

    public bool $mandatory = true;

    public bool $requiresCheckbox = true;

    public bool $requiresDocument = false;

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
        $data->type = $task->getType();
        $data->dueDate = $task->getDueDate();
        $data->assignedUser = $task->getAssignedUser();
        $data->assignedRole = $task->getAssignedRole();
        $data->mandatory = $task->isMandatory();
        $data->requiresCheckbox = $task->requiresCheckbox();
        $data->requiresDocument = $task->requiresDocument();

        return $data;
    }
}
