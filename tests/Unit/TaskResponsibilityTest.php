<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Role;
use App\Entity\Task;
use App\Entity\TaskResponsibility;
use App\Entity\Unit;
use App\Entity\User;
use App\Enum\TaskType;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

/**
 * A task's responsibility is "role + (department)", resolved live from the role's current holders
 * (narrowed to the department when the role is per-department). If whoever holds the role there
 * changes, the task follows the new holder — nothing is stored.
 */
final class TaskResponsibilityTest extends TestCase
{
    private function unit(string $name): Unit
    {
        return (new Unit())->setCode(strtolower($name))->setName($name);
    }

    private function user(string $name, ?Unit $unit = null, bool $active = true): User
    {
        $user = (new User())->setFullName($name)->setEmail(strtolower($name).'@centro.test')->setActive($active);

        return null !== $unit ? $user->setUnit($unit) : $user;
    }

    /**
     * A role whose holders collection is populated directly (addAssignedRole only touches the User side).
     */
    private function role(string $name, bool $perDepartment, User ...$holders): Role
    {
        $role = (new Role())->setCode(strtolower($name))->setName($name)->setPerDepartment($perDepartment);
        (new \ReflectionProperty(Role::class, 'users'))->setValue($role, new ArrayCollection($holders));

        return $role;
    }

    private function task(): Task
    {
        return new Task('Memoria', '2025-2026', new \DateTimeImmutable('2026-05-31'), TaskType::SIMPLE);
    }

    public function testPerDepartmentRoleResolvesToHoldersInThatDepartment(): void
    {
        $maths = $this->unit('Matematicas');
        $lengua = $this->unit('Lengua');
        $ana = $this->user('Ana', $maths);
        $beto = $this->user('Beto', $lengua);
        $teacher = $this->role('Profesor', true, $ana, $beto);

        $responsibility = new TaskResponsibility($teacher, $maths);

        self::assertSame([$ana], $responsibility->holders(), 'only the holder in that department');
        self::assertTrue($responsibility->isHeldBy($ana));
        self::assertFalse($responsibility->isHeldBy($beto));
    }

    public function testFollowsWhenTheHolderChanges(): void
    {
        $maths = $this->unit('Matematicas');
        $ana = $this->user('Ana', $maths);
        $headDept = $this->role('Jefatura de departamento', true, $ana);
        $task = $this->task()->setResponsibility(new TaskResponsibility($headDept, $maths));

        self::assertTrue($task->isOwnedBy($ana));
        self::assertSame($ana, $task->resolveResponsible());

        // The head of the department changes: Ana out, Beto in (same role, same department).
        $beto = $this->user('Beto', $maths);
        (new \ReflectionProperty(Role::class, 'users'))->setValue($headDept, new ArrayCollection([$beto]));

        self::assertFalse($task->isOwnedBy($ana), 'the old head no longer owns it');
        self::assertTrue($task->isOwnedBy($beto));
        self::assertSame($beto, $task->resolveResponsible(), 'the task follows the new head, with nothing copied');
    }

    public function testCentreWideRoleIgnoresDepartment(): void
    {
        $ana = $this->user('Ana', $this->unit('Matematicas'));
        $direction = $this->role('Direccion', false, $ana);

        $responsibility = new TaskResponsibility($direction, null);

        self::assertSame([$ana], $responsibility->holders());
        self::assertSame('Direccion', $responsibility->label());
    }

    public function testInactiveHoldersAreExcluded(): void
    {
        $maths = $this->unit('Matematicas');
        $active = $this->user('Ana', $maths);
        $inactive = $this->user('Beto', $maths, active: false);
        $teacher = $this->role('Profesor', true, $active, $inactive);

        self::assertSame([$active], (new TaskResponsibility($teacher, $maths))->holders());
    }

    public function testDelegationOverridesTheResponsibility(): void
    {
        $maths = $this->unit('Matematicas');
        $head = $this->user('Ana', $maths);
        $delegatee = $this->user('Beto', $maths);
        $headDept = $this->role('Jefatura de departamento', true, $head);
        $task = $this->task()
            ->setResponsibility(new TaskResponsibility($headDept, $maths))
            ->setDelegatedTo($delegatee);

        self::assertTrue($task->isOwnedBy($delegatee));
        self::assertFalse($task->isOwnedBy($head), 'once delegated, the head no longer owns it');
        self::assertSame($delegatee, $task->resolveResponsible());
    }

    public function testLabelIncludesTheDepartmentForPerDepartmentRoles(): void
    {
        $maths = $this->unit('Matematicas');
        $teacher = $this->role('Profesor', true);

        self::assertSame('Profesor de Matematicas', (new TaskResponsibility($teacher, $maths))->label());
    }
}
