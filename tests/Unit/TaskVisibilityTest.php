<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Role;
use App\Entity\Task;
use App\Entity\TaskResponsibility;
use App\Entity\Department;
use App\Entity\User;
use App\Enum\TaskType;
use App\Repository\UserRepository;
use App\Service\OrganizationHierarchy;
use App\Service\TaskVisibility;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the task visibility scope: a user sees their own tasks and those they command by
 * rank (relative to each task's role + department); an admin sees them all. Role-derived, no database.
 */
final class TaskVisibilityTest extends TestCase
{
    private function role(string $code, ?int $level, bool $perDepartment = false): Role
    {
        return (new Role())->setCode($code)->setName($code)->setHierarchyLevel($level)->setPerDepartment($perDepartment);
    }

    private function user(string $name, ?Department $unit, Role ...$roles): User
    {
        $user = (new User())->setFullName($name)->setEmail($name.'@example.test');
        if (null !== $unit) {
            $user->setUnit($unit);
        }
        foreach ($roles as $role) {
            $user->addAssignedRole($role);
        }

        return $user;
    }

    private function task(?Department $unit, ?User $assignee, ?TaskResponsibility $responsibility = null): Task
    {
        $task = new Task('Tarea', '2025-2026', new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE);
        $task->setUnit($unit);
        if (null !== $assignee) {
            $task->setAssignedUser($assignee);
        }
        if (null !== $responsibility) {
            $task->setResponsibility($responsibility);
        }

        return $task;
    }

    /**
     * A director (centre-wide), a head of the Maths department and a plain teacher, plus one task per
     * level (a teacher task, the head's task, a centre task).
     *
     * @return array{TaskVisibility, director: User, head: User, teacher: User, mine: Task, dept: Task, top: Task}
     */
    private function scenario(): array
    {
        $direction = $this->role('direction', 40);
        $headDept = $this->role('head_dept', 10, perDepartment: true);
        $teacherRole = $this->role('teacher', null, perDepartment: true);
        $maths = (new Department())->setCode('maths')->setName('Matemáticas');

        $director = $this->user('director', $maths, $direction);
        $head = $this->user('head', $maths, $headDept);
        $teacher = $this->user('teacher', $maths, $teacherRole);

        $mine = $this->task($maths, $teacher, new TaskResponsibility($teacherRole, $maths));
        $dept = $this->task($maths, $head, new TaskResponsibility($headDept, $maths));
        $top = $this->task(null, $director, new TaskResponsibility($direction, null));

        $hierarchy = new OrganizationHierarchy($this->createMock(UserRepository::class));

        return [
            new TaskVisibility($hierarchy),
            'director' => $director, 'head' => $head, 'teacher' => $teacher,
            'mine' => $mine, 'dept' => $dept, 'top' => $top,
        ];
    }

    public function testTeacherSeesOnlyTheirOwnTasks(): void
    {
        ['teacher' => $teacher, 'mine' => $mine, 'dept' => $dept, 'top' => $top, 0 => $visibility] = $this->scenario();

        self::assertSame([$mine], $visibility->visibleTo([$mine, $dept, $top], $teacher, false), 'a teacher only sees the task assigned to them');
    }

    public function testHeadSeesTheirDepartmentTasksButNotCentreTasksAbove(): void
    {
        ['head' => $head, 'mine' => $mine, 'dept' => $dept, 'top' => $top, 0 => $visibility] = $this->scenario();

        // The head of Maths commands the teacher tasks of Maths and owns their own, but does not
        // outrank a centre (dirección) task.
        self::assertSame([$mine, $dept], $visibility->visibleTo([$mine, $dept, $top], $head, false));
    }

    public function testDirectorSeesEveryTask(): void
    {
        ['director' => $director, 'mine' => $mine, 'dept' => $dept, 'top' => $top, 0 => $visibility] = $this->scenario();

        self::assertSame([$mine, $dept, $top], $visibility->visibleTo([$mine, $dept, $top], $director, false), 'a centre-wide role commands the whole school');
    }

    public function testAdminSeesEveryTaskRegardlessOfHierarchy(): void
    {
        ['teacher' => $teacher, 'mine' => $mine, 'dept' => $dept, 'top' => $top, 0 => $visibility] = $this->scenario();

        self::assertSame([$mine, $dept, $top], $visibility->visibleTo([$mine, $dept, $top], $teacher, true), 'an admin bypasses the scope');
    }

    public function testUnitlessTaskReachesItsOwnerAndAnyWholeSchoolSuperior(): void
    {
        ['teacher' => $teacher, 'director' => $director, 'head' => $head, 0 => $visibility] = $this->scenario();
        // An ad-hoc task with no responsibility and no unit: reaches its assignee, and a centre-wide
        // superior (dirección) who oversees everything — but not a department head, who commands by
        // department and has none to stand on here.
        $orphan = $this->task(null, $teacher);

        self::assertTrue($visibility->isVisibleTo($orphan, $teacher, false), 'the assignee sees their unit-less task');
        self::assertTrue($visibility->isVisibleTo($orphan, $director, false), 'a centre-wide superior oversees it');
        self::assertFalse($visibility->isVisibleTo($orphan, $head, false), 'a department head cannot reach a unit-less task');
        self::assertTrue($visibility->isVisibleTo($orphan, $director, true), 'an admin still sees it');
    }
}
