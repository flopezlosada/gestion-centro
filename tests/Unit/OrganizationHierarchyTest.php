<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Role;
use App\Entity\Task;
use App\Entity\TaskResponsibility;
use App\Entity\Unit;
use App\Entity\User;
use App\Enum\TaskType;
use App\Repository\UserRepository;
use App\Service\OrganizationHierarchy;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the role-derived chain of command: superiority is contextual to a task's (role,
 * department) and strict by rank, and a per-department rank commands only its own department.
 */
final class OrganizationHierarchyTest extends TestCase
{
    private Role $direction;
    private Role $headStudies;
    private Role $deputy;
    private Role $headDept;
    private Role $teacher;
    private Unit $maths;
    private Unit $lengua;

    protected function setUp(): void
    {
        $this->direction = $this->role('direction', 40);
        $this->headStudies = $this->role('head_of_studies', 30);
        $this->deputy = $this->role('head_of_studies_deputy', 20);
        $this->headDept = $this->role('head_dept', 10, perDepartment: true);
        $this->teacher = $this->role('teacher', null, perDepartment: true);
        $this->maths = (new Unit())->setCode('maths')->setName('Matemáticas');
        $this->lengua = (new Unit())->setCode('lengua')->setName('Lengua');
    }

    private function role(string $code, ?int $level, bool $perDepartment = false): Role
    {
        return (new Role())->setCode($code)->setName($code)->setHierarchyLevel($level)->setPerDepartment($perDepartment);
    }

    private function user(string $name, ?Unit $unit, Role ...$roles): User
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

    private function hierarchy(User ...$rankedUsers): OrganizationHierarchy
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findWithHierarchyRank')->willReturn($rankedUsers);

        return new OrganizationHierarchy($users);
    }

    public function testDepartmentHeadCommandsOnlyItsOwnDepartment(): void
    {
        $headMaths = $this->user('mates', $this->maths, $this->headDept, $this->teacher);
        $h = $this->hierarchy();

        self::assertTrue($h->outranks($headMaths, $this->teacher, $this->maths), 'commands teachers of its own department');
        self::assertFalse($h->outranks($headMaths, $this->teacher, $this->lengua), 'does NOT command another department');
        self::assertFalse($h->outranks($headMaths, $this->headDept, $this->maths), 'is not above its own rank');
    }

    public function testCentreWideRoleCommandsEveryDepartment(): void
    {
        $director = $this->user('dir', $this->maths, $this->direction, $this->teacher);
        $h = $this->hierarchy();

        self::assertTrue($h->outranks($director, $this->teacher, $this->lengua), 'direction commands any department');
        self::assertTrue($h->outranks($director, $this->headStudies, null), 'direction outranks the head of studies');
    }

    public function testStrictRankAmongLeadership(): void
    {
        $deputy = $this->user('adjunto', $this->maths, $this->deputy, $this->teacher);
        $h = $this->hierarchy();

        // The whole point Paco insisted on: an adjunto may NOT validate a task of dirección.
        self::assertFalse($h->outranks($deputy, $this->direction, null), 'the adjunto does not outrank direction');
        self::assertTrue($h->outranks($deputy, $this->teacher, $this->lengua), 'but the adjunto outranks a teacher anywhere');
    }

    public function testPlainTeacherCommandsNobody(): void
    {
        $teacher = $this->user('profe', $this->maths, $this->teacher);
        $h = $this->hierarchy();

        self::assertFalse($h->outranks($teacher, $this->teacher, $this->maths));
        self::assertFalse($h->commandsWholeSchool($teacher));
        self::assertNull($h->commandedDepartment($teacher));
    }

    public function testCommandHelpers(): void
    {
        $director = $this->user('dir', $this->maths, $this->direction, $this->teacher);
        $headMaths = $this->user('mates', $this->maths, $this->headDept, $this->teacher);
        $h = $this->hierarchy();

        self::assertTrue($h->commandsWholeSchool($director));
        self::assertNull($h->commandedDepartment($director), 'a centre-wide role is not a department command');
        self::assertFalse($h->commandsWholeSchool($headMaths));
        self::assertSame($this->maths, $h->commandedDepartment($headMaths));
    }

    public function testManagersAboveIsRankOrderedAndScoped(): void
    {
        $director = $this->user('dir', $this->maths, $this->direction, $this->teacher);
        $deputy = $this->user('adjunto', $this->lengua, $this->deputy, $this->teacher);
        $headMaths = $this->user('mates', $this->maths, $this->headDept, $this->teacher);
        $h = $this->hierarchy($director, $deputy, $headMaths);

        // A teaching task in Maths: nearest first = head of Maths (10), adjunto (20, centre-wide), director (40).
        $mathsTask = (new Task('t', '2025-2026', new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE))
            ->setResponsibility(new TaskResponsibility($this->teacher, $this->maths));
        self::assertSame([$headMaths, $deputy, $director], $h->managersAbove($mathsTask));

        // A teaching task in Lengua: the head of Maths drops out (per-department rank, wrong department).
        $lenguaTask = (new Task('t', '2025-2026', new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE))
            ->setResponsibility(new TaskResponsibility($this->teacher, $this->lengua));
        self::assertSame([$deputy, $director], $h->managersAbove($lenguaTask));
    }
}
