<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Enum\TaskType;
use App\Repository\TaskTemplateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A recurring task of the school's annual cycle (the valuable core of the catalogue). It is a
 * template, not a dated task: it is instantiated into a concrete {@see Task} each course, and the
 * due date is fixed at instantiation — it is never inherited from the template.
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
}
