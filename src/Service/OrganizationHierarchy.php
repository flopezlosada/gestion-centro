<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Role;
use App\Entity\Task;
use App\Entity\Department;
use App\Entity\User;
use App\Repository\UserRepository;

/**
 * The single source of truth for "who is above whom", derived from the ROLES people hold — never from
 * a unit's manager or a tree of units. A role may carry a chain-of-command rank ({@see
 * Role::getHierarchyLevel()}); scope comes from {@see Role::isPerDepartment()} (a per-department ranked
 * role — jefe de departamento — commands only its own department; a centre-wide ranked role — the
 * leadership team — commands the whole school).
 *
 * Superiority is CONTEXTUAL, relative to a task's responsibility (its role + department), not a fixed
 * label on a person: the head of studies is a subordinate docente inside her own department, so her
 * jefe de departamento can hand her a teaching task there. Rank is strict everywhere (management,
 * validation and escalation): you command a task only if your rank is strictly above the task's
 * responsibility role rank, in scope.
 *
 * {@see outranks()} and the "commands" helpers are pure (they read only the actor's roles). Only
 * {@see managersAbove()} — which must find OTHER people to escalate to — hits the database.
 */
final class OrganizationHierarchy
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    /**
     * Whether the actor outranks the given responsibility (a role in a department). The department is
     * only relevant to per-department ranked roles: a centre-wide ranked role commands every
     * department; a per-department one commands only the actor's own department. A null target role
     * counts as rank 0 (a plain assignment anyone ranked is above).
     *
     * @param User      $actor      the potential superior
     * @param Role|null $targetRole the responsibility role to command (null → rank 0)
     * @param Department|null $dept       the department the responsibility is scoped to (null for centre-wide)
     *
     * @return bool true if the actor's rank is strictly above the target, in scope
     */
    public function outranks(User $actor, ?Role $targetRole, ?Department $dept): bool
    {
        $target = $targetRole?->getHierarchyLevel() ?? 0;

        foreach ($actor->getAssignedRoles() as $role) {
            $level = $role->getHierarchyLevel();
            if (null === $level || $level <= $target) {
                continue;
            }
            if (!$role->isPerDepartment()) {
                return true; // centre-wide rank commands the whole school
            }
            if (null !== $dept && $actor->getUnit() === $dept) {
                return true; // per-department rank commands only its own department
            }
        }

        return false;
    }

    /**
     * Whether the actor is a superior of the task: outranks its responsibility (role + department).
     * Falls back to the task's own unit when the responsibility carries no department.
     *
     * @param User $actor the potential superior
     * @param Task $task  the task to command
     *
     * @return bool true if the actor may manage/validate this task by rank
     */
    public function isSuperiorOfTask(User $actor, Task $task): bool
    {
        $responsibility = $task->getResponsibility();

        return $this->outranks($actor, $responsibility?->getRole(), $responsibility?->getUnit() ?? $task->getUnit());
    }

    /**
     * The people above a task in the chain of command, nearest first (lowest rank above the task, up
     * to the top). Used to escalate an overdue task. Strict by rank and scoped: only people whose
     * applicable rank is above the task's responsibility rank are included.
     *
     * @param Task $task the task to escalate
     *
     * @return list<User> the superiors, nearest first
     */
    public function managersAbove(Task $task): array
    {
        $responsibility = $task->getResponsibility();
        $dept = $responsibility?->getUnit() ?? $task->getUnit();
        $target = $responsibility?->getRole()?->getHierarchyLevel() ?? 0;

        $ranked = [];
        foreach ($this->users->findWithHierarchyRank() as $user) {
            $level = $this->applicableRank($user, $dept);
            if (null !== $level && $level > $target) {
                $ranked[] = ['user' => $user, 'level' => $level];
            }
        }
        usort($ranked, static fn (array $a, array $b): int => $a['level'] <=> $b['level']);

        return array_map(static fn (array $entry): User => $entry['user'], $ranked);
    }

    /**
     * Whether the actor commands the whole school (holds a centre-wide ranked role): may target any
     * department when creating tasks.
     *
     * @param User $actor the user
     *
     * @return bool true if the actor holds a centre-wide ranked role
     */
    public function commandsWholeSchool(User $actor): bool
    {
        foreach ($actor->getAssignedRoles() as $role) {
            if (null !== $role->getHierarchyLevel() && !$role->isPerDepartment()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The department the actor commands through a per-department ranked role (jefe de departamento):
     * their own department, or null if they hold no such role.
     *
     * @param User $actor the user
     *
     * @return Department|null the commanded department, or null
     */
    public function commandedDepartment(User $actor): ?Department
    {
        foreach ($actor->getAssignedRoles() as $role) {
            if (null !== $role->getHierarchyLevel() && $role->isPerDepartment()) {
                return $actor->getUnit();
            }
        }

        return null;
    }

    /**
     * The actor's own rank applicable to a department: the highest rank among their roles that apply
     * there (centre-wide roles always apply; per-department roles only in the actor's own department).
     * Null when the actor has no applicable rank.
     */
    private function applicableRank(User $actor, ?Department $dept): ?int
    {
        $best = null;
        foreach ($actor->getAssignedRoles() as $role) {
            $level = $role->getHierarchyLevel();
            if (null === $level) {
                continue;
            }
            $applies = !$role->isPerDepartment() || (null !== $dept && $actor->getUnit() === $dept);
            if ($applies && (null === $best || $level > $best)) {
                $best = $level;
            }
        }

        return $best;
    }
}
