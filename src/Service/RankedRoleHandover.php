<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Department;
use App\Entity\Role;
use App\Entity\User;
use App\Repository\TaskRepository;
use App\Repository\UserRepository;
use App\Util\SchoolYear;

/**
 * Hands a hierarchy post over to a new holder: when someone becomes the jefe de departamento (or
 * jefatura de estudios, dirección…), the tasks of that post follow them. Only the CURRENT course and
 * only OPEN (not yet validated) tasks move — closed tasks and past courses stay as a historical record
 * of who was responsible then.
 *
 * The move is a reassignment of the stored assignee ({@see User}); the structural responsibility (role
 * + department) is unchanged and already resolves live to the new holder. Reassigning the assignee is
 * what makes the tasks show up in the new holder's agenda and reminders.
 */
final class RankedRoleHandover
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * Makes the given user the SOLE holder of a ranked post: strips the role from any other current
     * holder in the same scope (its department for a per-department role, the whole school for a
     * centre-wide one), gives it to the new holder, and hands the post's open current-course tasks over
     * to them. The single point that guarantees a post cannot be duplicated, whichever screen assigns
     * it. A no-op for a role with no rank.
     *
     * @param User $newHolder the person taking over the post
     * @param Role $role      the ranked role being taken over
     * @param \DateTimeImmutable $on the reference date for "current course"
     *
     * @return int how many tasks were handed over
     */
    public function takeOver(User $newHolder, Role $role, \DateTimeImmutable $on): int
    {
        if (!$role->isHierarchical()) {
            return 0;
        }

        // Single holder per scope: strip the role from any other current holder in scope.
        $scope = $role->isPerDepartment() ? $newHolder->getUnit() : null;
        foreach ($this->users->findActiveByRole($role) as $holder) {
            if ($holder !== $newHolder && (null === $scope || $holder->getUnit() === $scope)) {
                $holder->removeAssignedRole($role);
            }
        }
        if (!$newHolder->holdsRole($role)) {
            $newHolder->addAssignedRole($role);
        }

        return $this->toNewHolder($newHolder, $role, $on);
    }

    /**
     * Reassigns the open, current-course tasks of a ranked role (in the new holder's scope) to that
     * new holder. A no-op for a role with no rank — only hierarchy posts hand over.
     *
     * @param User $newHolder the person taking over the post
     * @param Role $role      the ranked role being taken over
     * @param \DateTimeImmutable $on the reference date for "current course" (today, injected for tests)
     *
     * @return int how many tasks were handed over
     */
    public function toNewHolder(User $newHolder, Role $role, \DateTimeImmutable $on): int
    {
        if (!$role->isHierarchical()) {
            return 0;
        }

        // A per-department post commands its own department; a centre-wide post carries no department.
        $unit = $role->isPerDepartment() ? $newHolder->getUnit() : null;
        $tasks = $this->tasks->findOpenByResponsibility($role, $unit, SchoolYear::current($on));

        foreach ($tasks as $task) {
            $task->setAssignedUser($newHolder);
        }

        return \count($tasks);
    }

    /**
     * Leaves the open, current-course tasks of a ranked post unassigned when it is vacated with no
     * successor: they drop out of the outgoing holder's agenda and wait for the next holder, who will
     * pick them up via {@see toNewHolder()}. Structural responsibility (role + department) is untouched.
     *
     * @param Role            $role the ranked role being vacated
     * @param Department|null $unit the department the post is scoped to (null for a centre-wide post)
     * @param \DateTimeImmutable $on the reference date for "current course"
     *
     * @return int how many tasks were left unassigned
     */
    public function vacate(Role $role, ?Department $unit, \DateTimeImmutable $on): int
    {
        if (!$role->isHierarchical()) {
            return 0;
        }

        $scope = $role->isPerDepartment() ? $unit : null;
        $tasks = $this->tasks->findOpenByResponsibility($role, $scope, SchoolYear::current($on));

        foreach ($tasks as $task) {
            $task->setAssignedUser(null);
        }

        return \count($tasks);
    }
}
