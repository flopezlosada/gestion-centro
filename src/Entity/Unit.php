<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Repository\UnitRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An organizational unit of the school (a department, the head of studies office, the management
 * team…). Units form the chain of command via {@see $parent}: escalation and validation walk up
 * from a unit to its parent, up to the top (management). Each unit has a {@see $manager} — the
 * person who validates its tasks and receives escalations.
 *
 * The concrete org chart is provided by the school and seeded later; only the structure is modelled
 * here (see the arranque-tecnico notes).
 */
#[ORM\Entity(repositoryClass: UnitRepository::class)]
#[ORM\Table(name: 'org_unit')]
#[UniqueEntity(fields: ['code'], message: 'Ya existe una unidad con ese código.')]
class Unit implements Auditable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Stable machine identifier (e.g. "maths", "head_of_studies", "management"). */
    #[ORM\Column(length: 50, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 50)]
    private string $code;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $name;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * Retired units are kept for history (so past tasks keep their context) but no longer used for
     * new assignments. Soft delete on purpose: a physical delete would fire the database-level
     * ON DELETE SET NULL on referencing rows, which bypasses Doctrine and is not audited.
     */
    #[ORM\Column]
    private bool $active = true;

    /**
     * The unit directly above in the chain of command (null for the top unit, e.g. management).
     * Escalation walks up this link.
     */
    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?self $parent = null;

    /** @var Collection<int, Unit> */
    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class)]
    private Collection $children;

    /**
     * The person responsible for this unit (head of department, head of studies, director…): who
     * validates its tasks and is the first escalation target. Nullable while the org chart is
     * incomplete.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'manager_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $manager = null;

    public function __construct()
    {
        $this->children = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

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

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): static
    {
        $this->parent = $parent;

        return $this;
    }

    /**
     * @return Collection<int, Unit> the units directly below this one
     */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function getManager(): ?User
    {
        return $this->manager;
    }

    public function setManager(?User $manager): static
    {
        $this->manager = $manager;

        return $this;
    }
}
