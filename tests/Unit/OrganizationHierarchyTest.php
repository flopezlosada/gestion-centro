<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Role;
use App\Entity\Task;
use App\Entity\Unit;
use App\Entity\User;
use App\Enum\TaskType;
use App\Service\OrganizationHierarchy;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the chain of command: the managers above a unit and the "is superior of" check,
 * built on an in-memory entity graph (no database).
 */
final class OrganizationHierarchyTest extends TestCase
{
    private function user(string $email): User
    {
        return (new User())->setFullName($email)->setEmail($email.'@example.test');
    }

    /**
     * @return array{OrganizationHierarchy, maths: Unit, director: User, headStudies: User, headMaths: User}
     */
    private function tree(): array
    {
        $director = $this->user('director');
        $headStudies = $this->user('jefatura');
        $headMaths = $this->user('mates');

        $management = (new Unit())->setCode('mgmt')->setName('Dirección')->setManager($director);
        $studies = (new Unit())->setCode('studies')->setName('Jefatura de estudios')->setManager($headStudies)->setParent($management);
        $maths = (new Unit())->setCode('maths')->setName('Matemáticas')->setManager($headMaths)->setParent($studies);

        return [new OrganizationHierarchy(), 'maths' => $maths, 'director' => $director, 'headStudies' => $headStudies, 'headMaths' => $headMaths];
    }

    public function testManagersAboveWalksTheChainNearestFirst(): void
    {
        ['maths' => $maths, 'director' => $director, 'headStudies' => $headStudies, 'headMaths' => $headMaths, 0 => $h] = $this->tree();

        self::assertSame([$headMaths, $headStudies, $director], $h->managersAbove($maths));
    }

    public function testSuperiorRecognisesUnitManagerAndAncestors(): void
    {
        ['maths' => $maths, 'director' => $director, 'headStudies' => $headStudies, 'headMaths' => $headMaths, 0 => $h] = $this->tree();

        self::assertTrue($h->isSuperiorOf($headMaths, $maths), 'the unit manager is a superior of its own unit');
        self::assertTrue($h->isSuperiorOf($headStudies, $maths), 'an ancestor manager is a superior');
        self::assertTrue($h->isSuperiorOf($director, $maths), 'the top manager is a superior');
    }

    public function testOutsiderIsNotSuperior(): void
    {
        ['maths' => $maths, 0 => $h] = $this->tree();
        $teacher = $this->user('teacher');

        self::assertFalse($h->isSuperiorOf($teacher, $maths));
        self::assertFalse($h->isSuperiorOf($teacher, null), 'with no unit no superior can be determined');
    }

    public function testChainWithCycleTerminates(): void
    {
        $a = (new Unit())->setCode('a')->setName('A');
        $b = (new Unit())->setCode('b')->setName('B');
        $a->setParent($b);
        $b->setParent($a);

        self::assertSame([], (new OrganizationHierarchy())->managersAbove($a), 'a cycle must not loop forever');
    }

    private function task(?Unit $unit): Task
    {
        $task = new Task('Memoria', '2025-2026', new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE);

        return null !== $unit ? $task->setUnit($unit) : $task;
    }

    public function testAssigneeSeesTheirTask(): void
    {
        ['maths' => $maths, 0 => $h] = $this->tree();
        $teacher = $this->user('teacher');
        $task = $this->task($maths)->setAssignedUser($teacher);

        self::assertTrue($h->canSeeTask($teacher, $task));
    }

    public function testSuperiorSeesDepartmentTask(): void
    {
        ['maths' => $maths, 'director' => $director, 'headMaths' => $headMaths, 0 => $h] = $this->tree();
        $task = $this->task($maths)->setAssignedUser($this->user('someone'));

        self::assertTrue($h->canSeeTask($headMaths, $task), 'the department manager sees its tasks');
        self::assertTrue($h->canSeeTask($director, $task), 'a superior up the chain sees them too');
    }

    public function testAdminSeesEveryTask(): void
    {
        ['maths' => $maths, 0 => $h] = $this->tree();
        $admin = $this->user('tic');
        $admin->addAssignedRole((new Role())->setCode('tic')->setName('TIC')->setAdmin(true));
        $task = $this->task($maths)->setAssignedUser($this->user('someone'));

        self::assertTrue($h->canSeeTask($admin, $task));
    }

    public function testUnrelatedUserDoesNotSeeTask(): void
    {
        ['maths' => $maths, 0 => $h] = $this->tree();
        $outsider = $this->user('outsider');
        $task = $this->task($maths)->setAssignedUser($this->user('someone'));

        self::assertFalse($h->canSeeTask($outsider, $task), 'a teacher does not see another unit\'s task');
    }
}
