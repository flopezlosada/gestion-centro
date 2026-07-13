<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TaskResponsibilityRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Who a task is the job of, chosen as a role plus — when the role is scoped to a department
 * ({@see Role::isPerDepartment()}) — that department. The responsible people are NOT stored: they are
 * resolved live from the role's current holders (filtered to the department when applicable), so if
 * whoever holds "jefe de departamento de Matemáticas" changes, the task follows the new holder
 * automatically. A specific person is never picked here (that is what {@see Task::$delegatedTo} is for).
 */
#[ORM\Entity(repositoryClass: TaskResponsibilityRepository::class)]
#[ORM\Table(name: 'task_responsibility')]
class TaskResponsibility
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The responsible function. */
    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(name: 'role_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Role $role;

    /** The department, when the role is per-department; null for centre-wide roles. */
    #[ORM\ManyToOne(targetEntity: Unit::class)]
    #[ORM\JoinColumn(name: 'unit_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Unit $unit;

    public function __construct(Role $role, ?Unit $unit = null)
    {
        $this->role = $role;
        $this->unit = $unit;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRole(): Role
    {
        return $this->role;
    }

    public function getUnit(): ?Unit
    {
        return $this->unit;
    }

    /**
     * The people who hold this responsibility right now: the role's active holders, narrowed to the
     * department when the role is per-department. Resolved live, so a change of holder is reflected at
     * once with nothing stored.
     *
     * @return list<User> the current holders
     */
    public function holders(): array
    {
        $holders = array_filter(
            $this->role->getUsers()->toArray(),
            static fn (User $user): bool => $user->isActive(),
        );

        if (null !== $this->unit) {
            $unit = $this->unit;
            $holders = array_filter($holders, static fn (User $user): bool => $user->getUnit() === $unit);
        }

        return array_values($holders);
    }

    /**
     * Whether the given user is one of the current holders.
     *
     * @param User $user the person to check
     *
     * @return bool true if the user holds this responsibility now
     */
    public function isHeldBy(User $user): bool
    {
        return \in_array($user, $this->holders(), true);
    }

    /**
     * A short human label for lists and the task detail (e.g. "Jefatura de departamento de
     * Matemáticas", or just "Dirección" for a centre-wide role).
     *
     * @return string the label
     */
    public function label(): string
    {
        return null !== $this->unit ? $this->role->getName().' de '.$this->unit->getName() : $this->role->getName();
    }
}
