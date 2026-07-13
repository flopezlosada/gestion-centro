<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The task is a specific person's job — a fixed snapshot that does not follow any post or role. Used
 * for ad-hoc assignments and as the result of a delegation being materialised. Does not filter by
 * active status: an explicit assignment stands until changed (same as the old assignedUser did).
 */
#[ORM\Entity]
class PersonResponsibility extends TaskResponsibility
{
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function holders(): array
    {
        return null !== $this->user ? [$this->user] : [];
    }

    public function label(): string
    {
        return null !== $this->user ? $this->user->getFullName() : 'Sin responsable';
    }
}
