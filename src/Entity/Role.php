<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use App\Repository\RoleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A responsibility within the school (e.g. Direction, Head of Studies, Head of Department, Tutor,
 * Secretary).
 *
 * Roles are a configurable catalog, not a fixed enum: several people can share the same
 * responsibility (co-responsibles) and the admin can add people and split tasks. A
 * stable {@see $code} allows programmatic lookups (e.g. escalation rules) independently
 * of the human-facing {@see $name}.
 */
#[ORM\Entity(repositoryClass: RoleRepository::class)]
#[UniqueEntity(fields: ['code'], message: 'Ya existe un rol con ese código.')]
class Role implements Auditable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Stable machine identifier (e.g. "direction", "ems_manager"). Immutable in practice so
     * business rules can reference roles without depending on the display name.
     */
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
     * Read/write access per area: a map of area value => level value. Areas absent from the map
     * grant no access. Stored as a JSON object in MySQL (e.g. {"task":"write"}); an empty
     * map serialises as {} — keep that in mind for any future direct JSON queries.
     *
     * @var array<string, string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $permissions = [];

    /**
     * Superuser flag: when true this role grants full access to every {@see Area}, bypassing the
     * {@see $permissions} matrix entirely (see {@see \App\Security\Voter\AreaVoter}). It also makes
     * its holders ROLE_ADMIN — so it gates the /admin and /audit sections too. Explicit on purpose:
     * admin power is an auditable checkbox, not a side effect of naming a role "admin".
     */
    #[ORM\Column]
    private bool $admin = false;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class, mappedBy: 'assignedRoles')]
    private Collection $users;

    public function __construct()
    {
        $this->users = new ArrayCollection();
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

    public function isAdmin(): bool
    {
        return $this->admin;
    }

    public function setAdmin(bool $admin): static
    {
        $this->admin = $admin;

        return $this;
    }

    /**
     * Access level this role grants over the given area (NONE if unset).
     */
    public function getLevel(Area $area): PermissionLevel
    {
        $value = $this->permissions[$area->value] ?? null;

        // tryFrom (not from): an unknown/corrupt stored value degrades to NONE rather than
        // throwing and turning every protected request into a 500.
        return null !== $value ? (PermissionLevel::tryFrom($value) ?? PermissionLevel::NONE) : PermissionLevel::NONE;
    }

    public function setLevel(Area $area, PermissionLevel $level): static
    {
        if (PermissionLevel::NONE === $level) {
            unset($this->permissions[$area->value]);
        } else {
            $this->permissions[$area->value] = $level->value;
        }

        return $this;
    }

    /**
     * Whether this role grants at least the required level over the area.
     */
    public function allows(Area $area, PermissionLevel $required): bool
    {
        return $this->getLevel($area)->satisfies($required);
    }

    /**
     * How many areas this role grants at each level, for the one-line summary in the role list
     * ("Escritura en 4 · Lectura en 3 · Sin acceso al resto"). Admin roles bypass the matrix, so
     * callers should special-case {@see isAdmin()} before using these counts.
     *
     * @return array{write: int, read: int, none: int} number of areas per access level
     */
    public function levelCounts(): array
    {
        $counts = [
            PermissionLevel::WRITE->value => 0,
            PermissionLevel::READ->value => 0,
            PermissionLevel::NONE->value => 0,
        ];

        foreach (Area::cases() as $area) {
            ++$counts[$this->getLevel($area)->value];
        }

        return $counts;
    }

    /**
     * @return Collection<int, User> the people who currently hold this responsibility
     */
    public function getUsers(): Collection
    {
        return $this->users;
    }
}
