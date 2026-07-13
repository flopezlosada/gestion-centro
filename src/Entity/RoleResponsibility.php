<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The task belongs to a function held by many — any active holder of the {@see Role} may consider it
 * theirs (e.g. a voluntary task for "any teacher"). Resolved live from the role's current membership.
 */
#[ORM\Entity]
class RoleResponsibility extends TaskResponsibility
{
    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(name: 'role_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Role $role;

    public function __construct(Role $role)
    {
        $this->role = $role;
    }

    public function getRole(): ?Role
    {
        return $this->role;
    }

    public function holders(): array
    {
        if (null === $this->role) {
            return [];
        }

        return array_values(array_filter(
            $this->role->getUsers()->toArray(),
            static fn (User $user): bool => $user->isActive(),
        ));
    }

    public function label(): string
    {
        return null !== $this->role ? $this->role->getName() : 'Sin rol';
    }
}
