<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Department;
use App\Entity\Task;
use App\Entity\User;

/**
 * Decides which tasks a user may see in the course plan and the calendar. Tasks are universally
 * accessible (any authenticated user reaches the pages), so this is not an all-or-nothing gate but a
 * per-row scope built from the organisation chart, kept in one place so the list and the calendar
 * stay consistent.
 *
 * A task is visible when: it is the user's own (they hold it now, or they were assigned it and
 * delegated it down — they stay accountable); OR they lead the whole school (dirección/jefatura see
 * every task, since a director does not "outrank" a director-level task, they simply oversee all); OR
 * they head the task's department (a head sees every task of their department, delegated or not); OR
 * they outrank the task's role in its department; OR they are an admin.
 *
 * This is the "what I may see / supervise" scope (the course plan, the calendar, the task page). The
 * personal agenda on the homepage is a STRICTER "just what I must do" view — a task delegated down
 * leaves it — and does not go through here: it is built by {@see \App\Repository\TaskRepository::findAgendaFor()}.
 */
final class TaskVisibility
{
    public function __construct(private readonly OrganizationHierarchy $hierarchy)
    {
    }

    /**
     * Narrows a list of tasks down to the ones the user may see, preserving order. Filtering happens
     * in PHP on an already-fetched set (a whole course or calendar range), which keeps the queries
     * simple; the candidate lists are bounded by school year / month grid, so the set stays small.
     *
     * @param Task[] $tasks   the candidate tasks (e.g. a course or a calendar range)
     * @param User   $user    the person browsing
     * @param bool   $isAdmin whether the user is an admin (sees every task)
     *
     * @return list<Task> the tasks visible to the user
     */
    public function visibleTo(array $tasks, User $user, bool $isAdmin): array
    {
        if ($isAdmin) {
            return array_values($tasks);
        }

        return array_values(array_filter(
            $tasks,
            fn (Task $task): bool => $this->isVisibleTo($task, $user, false),
        ));
    }

    /**
     * Whether a single task is visible to the user: their own (assigned to them or to a role they
     * hold), one they were assigned and delegated down (they stay accountable), under a unit they are a
     * superior of, or any task for an admin. Same rule as {@see visibleTo()}, exposed per task so the
     * detail page can enforce it too.
     *
     * @param Task $task    the task to check
     * @param User $user    the person browsing
     * @param bool $isAdmin whether the user is an admin (sees every task)
     *
     * @return bool true if the user may see the task
     */
    public function isVisibleTo(Task $task, User $user, bool $isAdmin): bool
    {
        // Admin and whole-school leadership (dirección/jefatura) see every task, no exceptions — a
        // director does not "outrank" a director-level task (same rank), they simply oversee everything.
        if ($isAdmin || $this->hierarchy->commandsWholeSchool($user)) {
            return true;
        }

        // The current holder (the delegatee once delegated) and the assignee who delegated it down and
        // stays accountable — both keep sight of it.
        if ($task->isOwnedBy($user) || $task->getAssignedUser() === $user) {
            return true;
        }

        // A department head sees EVERY task of their department, delegated or not (delegation never
        // moves a task out of its department). Compare by object identity (like isOwnedBy), not by id:
        // the identity map makes both sides the same instance, and it does not trip over two null ids
        // (an unsaved task's absent department vs the commanded one) collapsing to "equal".
        $commanded = $this->hierarchy->commandedDepartment($user);
        if (null !== $commanded && $this->departmentOf($task) === $commanded) {
            return true;
        }

        // Otherwise, anyone who outranks the task's role in its department.
        return $this->hierarchy->isSuperiorOfTask($user, $task);
    }

    /**
     * The department a task belongs to: its responsibility's unit, or its own unit as a fallback (the
     * same source {@see OrganizationHierarchy::isSuperiorOfTask()} ranks against). Null for an ad-hoc,
     * unit-less task.
     *
     * @param Task $task the task
     *
     * @return Department|null the task's department, or null if it has none
     */
    private function departmentOf(Task $task): ?Department
    {
        return $task->getResponsibility()?->getUnit() ?? $task->getUnit();
    }
}
