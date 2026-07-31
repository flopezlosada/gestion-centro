<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Department;
use App\Entity\Role;
use App\Entity\Task;
use App\Entity\TaskResponsibility;
use App\Entity\User;
use App\Enum\DeliverableRequirement;
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

    /**
     * The concrete responsible person (third step) when the form edits ONE task, one of the role +
     * department holders.
     */
    public ?User $responsibilityUser = null;

    /**
     * The people to create a task FOR, when creating (the centre asked to be able to send the same task
     * to a whole department or to the entire staff at once). One task per person, each with its own
     * lifecycle: they deliver separately, so a single shared task would be a lie the moment the first
     * person delivers it.
     *
     * Two fields and not one because the form is genuinely two forms: creating takes a list, editing
     * takes the single assignee of the task in front of you. Callers read {@see responsibleUsers()},
     * which is the only place that knows which of the two is in play.
     *
     * @var list<User>
     */
    public array $responsibilityUsers = [];

    /**
     * Whether this submission came from the CREATE form (the one that takes a list) rather than the edit
     * one. Set by the controller, which is the only one that knows; the validator needs it to attach a
     * violation to a field that actually exists on screen — with nobody chosen at all, the two fields
     * are equally empty and there is nothing to tell them apart.
     */
    public bool $multiple = false;

    public bool $mandatory = true;

    /**
     * What the task demands in order to be delivered: nada, un enlace, un archivo o cualquiera de los
     * dos. Lo decide quien la crea.
     *
     * Deliberately NO $requiresCheckbox here. The form does not offer it (only the task TEMPLATE does,
     * see {@see \App\Form\TaskTemplateType}), and carrying it anyway corrupted the column: it was filled
     * from {@see \App\Entity\Task::requiresCheckbox()}, which is DERIVED (`checkbox && !document`), and
     * then written straight back — so merely editing a task with a deliverable persisted
     * requires_checkbox = 0, and dropping the document afterwards left a task that could not be closed
     * any way at all. A field the form cannot edit has no business in its data object.
     */
    public DeliverableRequirement $deliverable = DeliverableRequirement::NONE;

    /**
     * The people this submission is about: the list when creating, or the single assignee when editing.
     * The ONLY place that knows which of the two fields is in play, so no caller has to.
     *
     * @return list<User> the chosen people, possibly empty
     */
    public function responsibleUsers(): array
    {
        if ([] !== $this->responsibilityUsers) {
            return array_values($this->responsibilityUsers);
        }

        return null !== $this->responsibilityUser ? [$this->responsibilityUser] : [];
    }

    /**
     * The department a task for this person belongs to. It is the one chosen in the cascade, and — when
     * the form was sent WITHOUT one, which is how "toda la plantilla, de todos los departamentos" is
     * expressed — the person's own. That is what makes one submission able to produce a task per
     * department without asking for the department fifteen times.
     *
     * @param User $person the person the task is for
     *
     * @return Department|null the department for that person's task
     */
    public function departmentFor(User $person): ?Department
    {
        if (null === $this->responsibilityRole || !$this->responsibilityRole->isPerDepartment()) {
            return null;
        }

        return $this->responsibilityUnit ?? $person->getUnit();
    }

    /**
     * Validates the responsibility cascade end to end: somebody has to be chosen, and every chosen
     * person must actually hold that role in the department their task will belong to. These are
     * cross-field rules a single-field constraint cannot express.
     *
     * The department is NOT demanded any more when several people are picked: leaving it empty is how
     * one says "de todos los departamentos" ({@see departmentFor()}). With a single person it is not
     * demanded either — their own department answers it — and the holder check below still catches
     * anyone who does not hold the role.
     *
     * @param ExecutionContextInterface $context the validation context to attach violations to
     */
    #[Assert\Callback]
    public function validateResponsibility(ExecutionContextInterface $context): void
    {
        if (null === $this->responsibilityRole) {
            return; // the NotNull on the role already reports the empty case
        }

        $field = $this->multiple ? 'responsibilityUsers' : 'responsibilityUser';
        $people = $this->responsibleUsers();
        if ([] === $people) {
            $context->buildViolation('Elige al menos una persona responsable.')->atPath($field)->addViolation();

            return;
        }

        // Coupled choice: each person must be one of the current holders of role + (their department).
        foreach ($people as $person) {
            if (!(new TaskResponsibility($this->responsibilityRole, $this->departmentFor($person)))->isHeldBy($person)) {
                $context->buildViolation(sprintf('%s no tiene ese rol en ese departamento.', $person->getFullName()))
                    ->atPath($field)
                    ->addViolation();
            }
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
        $data->deliverable = $task->getDeliverable();

        $responsibility = $task->getResponsibility();
        if (null !== $responsibility) {
            $data->responsibilityRole = $responsibility->getRole();
            $data->responsibilityUnit = $responsibility->getUnit();
        }
        $data->responsibilityUser = $task->getAssignedUser();

        return $data;
    }
}
