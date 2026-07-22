<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A person who uses the system. Holds one or more {@see Role}s (a person can be responsible for
 * several areas, and an area can have several co-responsibles).
 *
 * Passwordless: authentication is by magic link / SSO, so no credentials are stored. The role
 * collection is exposed as {@see getAssignedRoles()} on purpose: the name getRoles() is reserved
 * for Symfony's UserInterface contract (which returns string[]), to avoid a signature clash.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[UniqueEntity(fields: ['email'], message: 'Ya existe un usuario con ese correo.')]
class User implements UserInterface, Auditable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    private string $fullName;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private string $email;

    #[ORM\Column]
    private bool $active = true;

    /**
     * The teacher's stable code in Peñalara GHC (the resolved timetable's {@code X_EMPLEADO}). Set
     * once during timetable reconciliation and then used to re-link the imported schedule on every
     * later import without re-matching by name. Nullable because non-teaching users (or teachers not
     * yet reconciled) have none; unique so a Peñalara teacher maps to exactly one person.
     */
    #[ORM\Column(name: 'penalara_code', length: 32, unique: true, nullable: true)]
    #[Assert\Length(max: 32)]
    private ?string $penalaraCode = null;

    /** @var Collection<int, Role> */
    #[ORM\ManyToMany(targetEntity: Role::class, inversedBy: 'users')]
    #[ORM\JoinTable(name: 'user_role')]
    private Collection $assignedRoles;

    /**
     * The unit (department, office…) this person belongs to, used to walk the chain of command for
     * escalation and validation. Nullable while the org chart is incomplete.
     */
    #[ORM\ManyToOne(targetEntity: Department::class)]
    #[ORM\JoinColumn(name: 'unit_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Department $unit = null;

    public function __construct()
    {
        $this->assignedRoles = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFullName(): string
    {
        return $this->fullName;
    }

    public function setFullName(string $fullName): static
    {
        $this->fullName = $fullName;

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        // Store normalised so lookups and the unique index are consistent.
        $this->email = strtolower(trim($email));

        return $this;
    }

    public function getUnit(): ?Department
    {
        return $this->unit;
    }

    public function setUnit(?Department $unit): static
    {
        $this->unit = $unit;

        return $this;
    }

    public function getPenalaraCode(): ?string
    {
        return $this->penalaraCode;
    }

    public function setPenalaraCode(?string $penalaraCode): static
    {
        $this->penalaraCode = null !== $penalaraCode ? trim($penalaraCode) : null;

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
     * The responsibilities held by this user. Named to avoid clashing with
     * {@see \Symfony\Component\Security\Core\User\UserInterface::getRoles()}.
     *
     * @return Collection<int, Role>
     */
    public function getAssignedRoles(): Collection
    {
        return $this->assignedRoles;
    }

    public function addAssignedRole(Role $role): static
    {
        if (!$this->assignedRoles->contains($role)) {
            $this->assignedRoles->add($role);
            $role->linkHolder($this);
        }

        return $this;
    }

    public function removeAssignedRole(Role $role): static
    {
        if ($this->assignedRoles->removeElement($role)) {
            $role->unlinkHolder($this);
        }

        return $this;
    }

    /**
     * Whether this user holds the given responsibility. Single definition of "mine", shared by the
     * dashboard worklist and the "Qué toca" scope filter. Compares by persisted id, so an unpersisted
     * role is never considered held.
     *
     * @param Role $role the responsibility to check
     *
     * @return bool true if the user has this role assigned
     */
    public function holdsRole(Role $role): bool
    {
        $id = $role->getId();
        if (null === $id) {
            return false;
        }

        return $this->assignedRoles->exists(static fn (int $key, Role $held): bool => $held->getId() === $id);
    }

    /**
     * Whether the user holds a role with the given code (e.g. 'direction'). Used for type-based
     * document approval, where the approving role is identified by its code.
     *
     * @param string $code the role code to look for
     *
     * @return bool true if the user has a role with that code
     */
    public function holdsRoleCode(string $code): bool
    {
        return $this->assignedRoles->exists(static fn (int $key, Role $held): bool => $held->getCode() === $code);
    }

    /**
     * Unique identifier used by the security system (the e-mail address).
     */
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * Security roles derived from the assigned responsibilities. Every authenticated user has
     * ROLE_USER; holding any role flagged as admin (see {@see Role::isAdmin()}) adds ROLE_ADMIN,
     * which gates the sensitive /audit trail and bypasses the per-area matrix in
     * {@see \App\Security\Voter\AreaVoter} (so it also opens the /admin back-office, gated by write
     * access to {@see \App\Enum\Area::ADMINISTRATION}). Admin power is therefore an explicit flag,
     * not a side effect of a role's code.
     *
     * @return string[]
     */
    public function getRoles(): array
    {
        $roles = ['ROLE_USER'];
        foreach ($this->assignedRoles as $role) {
            if ($role->isAdmin()) {
                $roles[] = 'ROLE_ADMIN';
            }
        }

        return array_values(array_unique($roles));
    }

    /**
     * No-op: this is a passwordless system (magic link / SSO), so there are no sensitive
     * credentials to erase.
     */
    public function eraseCredentials(): void
    {
    }
}
