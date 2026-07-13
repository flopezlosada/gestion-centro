<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Role;
use App\Entity\Task;
use App\Entity\Unit;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Form-backing object for creating/editing a {@see Task}. The responsibility is chosen as a cascade:
 * a role, and — only when the role is per-department ({@see Role::isPerDepartment()}) — its department.
 * The controller turns that into a {@see \App\Entity\TaskResponsibility}; the responsible people are
 * derived from it, never picked one by one.
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
    public ?Unit $responsibilityUnit = null;

    public bool $mandatory = true;

    public bool $requiresCheckbox = true;

    public bool $requiresDocument = false;

    /**
     * A per-department role needs a department; a centre-wide role must not carry one. The cross-field
     * rule a single-field constraint cannot express.
     *
     * @param ExecutionContextInterface $context the validation context to attach violations to
     */
    #[Assert\Callback]
    public function validateResponsibility(ExecutionContextInterface $context): void
    {
        if (null === $this->responsibilityRole) {
            return; // the NotNull on the role already reports the empty case
        }

        if ($this->responsibilityRole->isPerDepartment() && null === $this->responsibilityUnit) {
            $context->buildViolation('Elige el departamento.')->atPath('responsibilityUnit')->addViolation();
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

        return $data;
    }
}
