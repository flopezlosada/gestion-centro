<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Task;
use App\Entity\User;

/**
 * Decides which tasks a user may see in the course plan and the calendar. Tasks are universally
 * accessible (any authenticated user reaches the pages), so this is not an all-or-nothing gate but a
 * per-row scope built from the organisation chart, kept in one place so the list and the calendar
 * stay consistent.
 *
 * A task is visible when it is the user's own (assigned to them or to a role they hold) or it falls
 * under a unit they are a superior of (they manage that unit or an ancestor of it); admins see every
 * task. The personal agenda on the homepage is a stricter "just mine" view and does not go through
 * here — it is built by {@see \App\Repository\TaskRepository::findAgendaFor()}.
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
            fn (Task $task): bool => $this->isOwn($task, $user) || $this->hierarchy->isSuperiorOf($user, $task->getUnit()),
        ));
    }

    /**
     * Whether the task is the user's own: assigned to them directly or to a role they hold. Mirrors
     * the "mine" notion used to decide who may work on a task.
     *
     * @param Task $task the task to check
     * @param User $user the person browsing
     *
     * @return bool true if the task belongs to the user
     */
    private function isOwn(Task $task, User $user): bool
    {
        if ($task->getAssignedUser() === $user) {
            return true;
        }

        $role = $task->getAssignedRole();

        return null !== $role && $user->holdsRole($role);
    }
}
