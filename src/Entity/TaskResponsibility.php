<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TaskResponsibilityRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * What structurally justifies a task being someone's job — the third axis, kept apart from hierarchy
 * ({@see Unit::$manager}, the single-holder post) and permissions ({@see Role}, the multi-holder
 * function). A task has exactly one, in one of three shapes ({@see CargoResponsibility},
 * {@see PersonResponsibility}, {@see RoleResponsibility}); single-table inheritance makes the illegal
 * "two shapes at once" state unrepresentable, instead of three nullable columns held together by a
 * convention. Delegation ({@see Task::$delegatedTo}) and history freezing ({@see Task::$completedBy})
 * are separate layers on {@see Task}, not shapes here.
 */
#[ORM\Entity(repositoryClass: TaskResponsibilityRepository::class)]
#[ORM\Table(name: 'task_responsibility')]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'kind', type: 'string', length: 20)]
#[ORM\DiscriminatorMap([
    'cargo' => CargoResponsibility::class,
    'person' => PersonResponsibility::class,
    'role' => RoleResponsibility::class,
])]
abstract class TaskResponsibility
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * The people who currently hold this responsibility, resolved live (never a stored snapshot), so
     * a change of a unit's manager or a role's membership is reflected immediately. Empty when nobody
     * holds it right now.
     *
     * @return list<User> the current holders
     */
    abstract public function holders(): array;

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
     * A short human label for lists and the task detail (e.g. "Jefatura de Matemáticas", a person's
     * name, or a role name).
     *
     * @return string the label
     */
    abstract public function label(): string;
}
