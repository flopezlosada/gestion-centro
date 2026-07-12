<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\DueDate\DueDateRule;
use App\DueDate\DueDateRuleFactory;
use App\Enum\TaskType;
use App\Repository\TaskTemplateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A recurring task of the school's annual cycle (the valuable core of the catalogue). It is a
 * template, not a dated task: it is instantiated into a concrete {@see Task} each course. The due
 * date of each instance may be computed by an optional {@see DueDateRule} (so the yearly generation
 * can stamp deadlines automatically), or left to be set by hand when no rule is defined.
 */
#[ORM\Entity(repositoryClass: TaskTemplateRepository::class)]
#[ORM\Table(name: 'task_template')]
class TaskTemplate implements Auditable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 200)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** The lifecycle this task follows once instantiated. */
    #[ORM\Column(length: 30, enumType: TaskType::class)]
    private TaskType $type = TaskType::SIMPLE;

    /** Mandatory tasks are assigned top-down; non-mandatory (voluntary) ones can be self-taken. */
    #[ORM\Column]
    private bool $mandatory = true;

    /**
     * The role responsible for the instances by default (e.g. head of department). Nullable for
     * templates whose responsible is decided per instance.
     */
    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(name: 'responsible_role_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Role $responsibleRole = null;

    /** Whether the task expects a document reference as a deliverable. */
    #[ORM\Column]
    private bool $requiresDocument = false;

    /** Whether the task expects a "done" checkbox declared by the assignee. */
    #[ORM\Column]
    private bool $requiresCheckbox = true;

    /** Retired templates are kept for history but no longer instantiated. */
    #[ORM\Column]
    private bool $active = true;

    /**
     * The rule that computes this template's due date(s) for a course, or null when the deadline is
     * set by hand each course. Persisted as JSON and exposed as a {@see DueDateRule} value object.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $dueDateRule = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function setType(TaskType $type): static
    {
        $this->type = $type;

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

    public function getResponsibleRole(): ?Role
    {
        return $this->responsibleRole;
    }

    public function setResponsibleRole(?Role $responsibleRole): static
    {
        $this->responsibleRole = $responsibleRole;

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

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    /**
     * The deadline rule for this template, or null when the due date is set by hand each course.
     *
     * @return DueDateRule|null the rule, rebuilt from its stored form
     */
    public function getDueDateRule(): ?DueDateRule
    {
        return null === $this->dueDateRule ? null : DueDateRuleFactory::fromArray($this->dueDateRule);
    }

    /**
     * Sets (or clears, with null) the deadline rule, storing it in its JSON form.
     *
     * @param DueDateRule|null $rule the rule to store, or null to compute the deadline by hand
     */
    public function setDueDateRule(?DueDateRule $rule): static
    {
        $this->dueDateRule = $rule?->toArray();

        return $this;
    }
}
