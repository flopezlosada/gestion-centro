<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Task;
use App\Entity\Unit;
use App\Entity\User;
use App\Enum\TaskType;
use App\Service\OrganizationHierarchy;
use App\Service\TaskVisibility;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the task visibility scope: a user sees their own tasks and those under a unit they
 * are a superior of, an admin sees them all. Built on an in-memory entity graph (no database).
 */
final class TaskVisibilityTest extends TestCase
{
    private function user(string $name): User
    {
        return (new User())->setFullName($name)->setEmail($name.'@example.test');
    }

    private function task(Unit $unit, ?User $assignee): Task
    {
        $task = new Task('Tarea', '2025-2026', new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE);
        $task->setUnit($unit);
        if (null !== $assignee) {
            $task->setAssignedUser($assignee);
        }

        return $task;
    }

    /**
     * A two-level chart (management → maths) with a director on top, a head on maths and a plain
     * teacher, plus one task per level.
     *
     * @return array{TaskVisibility, director: User, head: User, teacher: User, mine: Task, dept: Task, top: Task}
     */
    private function scenario(): array
    {
        $director = $this->user('director');
        $head = $this->user('head');
        $teacher = $this->user('teacher');

        $management = (new Unit())->setCode('mgmt')->setName('Dirección')->setManager($director);
        $maths = (new Unit())->setCode('maths')->setName('Matemáticas')->setManager($head)->setParent($management);

        $mine = $this->task($maths, $teacher);
        $dept = $this->task($maths, $head);
        $top = $this->task($management, $director);

        return [
            new TaskVisibility(new OrganizationHierarchy()),
            'director' => $director, 'head' => $head, 'teacher' => $teacher,
            'mine' => $mine, 'dept' => $dept, 'top' => $top,
        ];
    }

    public function testTeacherSeesOnlyTheirOwnTasks(): void
    {
        ['director' => $d, 'head' => $h, 'teacher' => $teacher, 'mine' => $mine, 'dept' => $dept, 'top' => $top, 0 => $visibility] = $this->scenario();

        self::assertSame([$mine], $visibility->visibleTo([$mine, $dept, $top], $teacher, false), 'a teacher only sees the task assigned to them');
    }

    public function testSuperiorSeesTasksInTheUnitsBelowThem(): void
    {
        ['head' => $head, 'mine' => $mine, 'dept' => $dept, 'top' => $top, 0 => $visibility] = $this->scenario();

        // The head of maths is a superior of the maths unit, so they see both tasks there, but not the
        // one on the management unit above them.
        self::assertSame([$mine, $dept], $visibility->visibleTo([$mine, $dept, $top], $head, false));
    }

    public function testTopManagerSeesEveryTaskInTheChart(): void
    {
        ['director' => $director, 'mine' => $mine, 'dept' => $dept, 'top' => $top, 0 => $visibility] = $this->scenario();

        self::assertSame([$mine, $dept, $top], $visibility->visibleTo([$mine, $dept, $top], $director, false), 'the top manager is a superior of the whole tree');
    }

    public function testAdminSeesEveryTaskRegardlessOfHierarchy(): void
    {
        ['teacher' => $teacher, 'mine' => $mine, 'dept' => $dept, 'top' => $top, 0 => $visibility] = $this->scenario();

        self::assertSame([$mine, $dept, $top], $visibility->visibleTo([$mine, $dept, $top], $teacher, true), 'an admin bypasses the scope');
    }
}
