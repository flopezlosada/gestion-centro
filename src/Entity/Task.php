<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Enum\TaskType;
use App\Repository\TaskRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A concrete task of a given course: either instantiated from a {@see TaskTemplate} or created
 * ad-hoc. Its {@see $status} is the Symfony Workflow marking (the state machine chosen by
 * {@see $type}); progress declared by the assignee and validation by the superior are distinct
 * transitions of that machine.
 *
 * Deliverables are references, not content: {@see $deliverableReference} holds an opaque link/code
 * to the document living in the school's cloud, never the document itself (Fase 1 legal boundary).
 */
#[ORM\Entity(repositoryClass: TaskRepository::class)]
#[ORM\Table(name: 'task')]
#[ORM\Index(name: 'idx_task_year_due', columns: ['school_year', 'due_date'])]
// Leads with due_date to serve findOpenDueOn() (deadline + open status) directly.
#[ORM\Index(name: 'idx_task_due_status', columns: ['due_date', 'status'])]
class Task implements Auditable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The template this task was instantiated from, if any (ad-hoc tasks have none). */
    #[ORM\ManyToOne(targetEntity: TaskTemplate::class)]
    #[ORM\JoinColumn(name: 'template_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TaskTemplate $template = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 200)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 30, enumType: TaskType::class)]
    private TaskType $type = TaskType::SIMPLE;

    /** Academic year in canonical "YYYY-YYYY" form (see {@see \App\Util\SchoolYear}). */
    #[ORM\Column(name: 'school_year', length: 9)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^\d{4}-\d{4}$/', message: 'El curso debe tener el formato "AAAA-AAAA".')]
    private string $schoolYear;

    /** Deadline, fixed at instantiation — never inherited from the template. */
    #[ORM\Column(name: 'due_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $dueDate;

    #[ORM\Column]
    private bool $mandatory = true;

    /** Current state-machine place (Symfony Workflow marking). */
    #[ORM\Column(length: 30)]
    private string $status;

    /** The role responsible for the task, if assigned by role. */
    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(name: 'assigned_role_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Role $assignedRole = null;

    /** The specific person responsible, if assigned to an individual. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'assigned_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $assignedUser = null;

    /** Unit context, used to walk the chain of command for validation and escalation. */
    #[ORM\ManyToOne(targetEntity: Unit::class)]
    #[ORM\JoinColumn(name: 'unit_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Unit $unit = null;

    #[ORM\Column]
    private bool $requiresDocument = false;

    #[ORM\Column]
    private bool $requiresCheckbox = true;

    /** The "done" checkbox declared by the assignee (progress, not validation). */
    #[ORM\Column]
    private bool $checkboxDone = false;

    /** Opaque reference/link to the deliverable in the school's cloud — never the content itself. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $deliverableReference = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** Who created the task (null for seeded/imported tasks). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    /**
     * Explicit delegation override: a superior may hand the task to a specific subordinate, on top of
     * (not replacing) its structural responsibility, so "who does it now" is this person while the
     * task still knows what it structurally is. Null means no delegation — the responsibility holder
     * does it.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'delegated_to_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $delegatedTo = null;

    /**
     * Who actually did the task, frozen once when it reaches the terminal "validated" state. A
     * historical fact (same idiom as {@see $createdBy}): later changes to the responsibility holder or
     * a unit's manager never rewrite it. Null while the task is still open.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'completed_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $completedBy = null;

    public function __construct(string $title, string $schoolYear, \DateTimeImmutable $dueDate, TaskType $type = TaskType::SIMPLE)
    {
        $this->title = $title;
        $this->schoolYear = $schoolYear;
        $this->dueDate = $dueDate;
        $this->type = $type;
        $this->status = $type->initialPlace();
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * Builds a task instance from a recurring template for a given course. Copies the definition but
     * NOT the date (fixed here, never inherited).
     *
     * @param TaskTemplate       $template   the recurring template
     * @param string             $schoolYear the target course in "YYYY-YYYY" form
     * @param \DateTimeImmutable $dueDate     the deadline for this instance
     *
     * @return self the new task instance
     */
    public static function fromTemplate(TaskTemplate $template, string $schoolYear, \DateTimeImmutable $dueDate): self
    {
        $task = new self($template->getTitle(), $schoolYear, $dueDate, $template->getType());
        $task->template = $template;
        $task->description = $template->getDescription();
        $task->mandatory = $template->isMandatory();
        $task->assignedRole = $template->getResponsibleRole();
        $task->requiresDocument = $template->requiresDocument();
        $task->requiresCheckbox = $template->requiresCheckbox();

        return $task;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTemplate(): ?TaskTemplate
    {
        return $this->template;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getType(): TaskType
    {
        return $this->type;
    }

    public function getSchoolYear(): string
    {
        return $this->schoolYear;
    }

    public function setSchoolYear(string $schoolYear): static
    {
        $this->schoolYear = $schoolYear;

        return $this;
    }

    public function getDueDate(): \DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function setDueDate(\DateTimeImmutable $dueDate): static
    {
        $this->dueDate = $dueDate;

        return $this;
    }

    public function isMandatory(): bool
    {
        return $this->mandatory;
    }

    public function setMandatory(bool $mandatory): static
    {
        $this->mandatory = $mandatory;

        return $this;
    }

    /**
     * The Workflow marking. Named getStatus/setStatus to match the method marking store in
     * config/packages/workflow.yaml.
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getAssignedRole(): ?Role
    {
        return $this->assignedRole;
    }

    public function setAssignedRole(?Role $assignedRole): static
    {
        $this->assignedRole = $assignedRole;

        return $this;
    }

    public function getAssignedUser(): ?User
    {
        return $this->assignedUser;
    }

    public function setAssignedUser(?User $assignedUser): static
    {
        $this->assignedUser = $assignedUser;

        return $this;
    }

    /**
     * Whether the task belongs to the given user: assigned to them directly or to a role they hold.
     * The single definition of a task being "theirs", shared by the visibility scope
     * ({@see \App\Service\TaskVisibility}) and the who-may-work-on-it check in the task controller.
     *
     * @param User $user the person to check
     *
     * @return bool true if the task is assigned to the user or to one of their roles
     */
    public function isOwnedBy(User $user): bool
    {
        // A delegation overrides the structural responsibility: only the delegatee owns it then.
        if (null !== $this->delegatedTo) {
            return $this->delegatedTo === $user;
        }

        if ($this->assignedUser === $user) {
            return true;
        }

        return null !== $this->assignedRole && $user->holdsRole($this->assignedRole);
    }

    public function getUnit(): ?Unit
    {
        return $this->unit;
    }

    public function setUnit(?Unit $unit): static
    {
        $this->unit = $unit;

        return $this;
    }

    public function requiresDocument(): bool
    {
        return $this->requiresDocument;
    }

    public function setRequiresDocument(bool $requiresDocument): static
    {
        $this->requiresDocument = $requiresDocument;

        return $this;
    }

    public function requiresCheckbox(): bool
    {
        return $this->requiresCheckbox;
    }

    public function setRequiresCheckbox(bool $requiresCheckbox): static
    {
        $this->requiresCheckbox = $requiresCheckbox;

        return $this;
    }

    public function isCheckboxDone(): bool
    {
        return $this->checkboxDone;
    }

    public function setCheckboxDone(bool $checkboxDone): static
    {
        $this->checkboxDone = $checkboxDone;

        return $this;
    }

    public function getDeliverableReference(): ?string
    {
        return $this->deliverableReference;
    }

    public function setDeliverableReference(?string $deliverableReference): static
    {
        $this->deliverableReference = $deliverableReference;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getDelegatedTo(): ?User
    {
        return $this->delegatedTo;
    }

    public function setDelegatedTo(?User $delegatedTo): static
    {
        $this->delegatedTo = $delegatedTo;

        return $this;
    }

    public function getCompletedBy(): ?User
    {
        return $this->completedBy;
    }

    public function setCompletedBy(?User $completedBy): static
    {
        $this->completedBy = $completedBy;

        return $this;
    }

    /**
     * The single person on the hook for this task right now: the delegatee if it has been delegated,
     * otherwise the assigned person. Resolved live; null when nobody concrete is set (e.g. a
     * role-only task with no individual). Extended in later phases to resolve a structural
     * responsibility (a unit's manager); today it reads the stored assignee.
     *
     * @return User|null the current responsible person, or null
     */
    public function resolveResponsible(): ?User
    {
        return $this->delegatedTo ?? $this->assignedUser;
    }

    /**
     * Who to show as responsible: the frozen {@see $completedBy} once the task is closed (a historical
     * fact that never changes), or the live {@see resolveResponsible()} while it is still open.
     *
     * @return User|null the person to display as responsible, or null
     */
    public function responsibleForDisplay(): ?User
    {
        return $this->completedBy ?? $this->resolveResponsible();
    }
}
