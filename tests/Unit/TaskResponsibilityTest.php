<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\CargoResponsibility;
use App\Entity\PersonResponsibility;
use App\Entity\Role;
use App\Entity\RoleResponsibility;
use App\Entity\Task;
use App\Entity\Unit;
use App\Entity\User;
use App\Enum\TaskType;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;

/**
 * The three responsibility shapes and how a task resolves its owner: a cargo follows the unit's
 * current manager (live), a person is a fixed snapshot, a role is its active members, delegation
 * overrides all of it, and an un-migrated task still falls back to its assignee.
 */
final class TaskResponsibilityTest extends TestCase
{
    private function user(string $name, bool $active = true): User
    {
        return (new User())->setFullName($name)->setEmail(strtolower($name).'@centro.test')->setActive($active);
    }

    private function unit(string $name): Unit
    {
        return (new Unit())->setCode(strtolower($name))->setName($name);
    }

    private function task(): Task
    {
        return new Task('Memoria final', '2025-2026', new \DateTimeImmutable('2026-05-31'), TaskType::SIMPLE);
    }

    public function testCargoResolvesToTheUnitManager(): void
    {
        $head = $this->user('Ana');
        $responsibility = new CargoResponsibility($this->unit('Matematicas')->setManager($head));

        self::assertSame([$head], $responsibility->holders());
        self::assertTrue($responsibility->isHeldBy($head));
    }

    public function testCargoFollowsWhenTheManagerChanges(): void
    {
        $ana = $this->user('Ana');
        $beto = $this->user('Beto');
        $maths = $this->unit('Matematicas')->setManager($ana);
        $task = $this->task()->setResponsibility(new CargoResponsibility($maths));

        self::assertTrue($task->isOwnedBy($ana));
        self::assertSame($ana, $task->resolveResponsible());

        $maths->setManager($beto);

        self::assertFalse($task->isOwnedBy($ana), 'the old head no longer owns it');
        self::assertTrue($task->isOwnedBy($beto));
        self::assertSame($beto, $task->resolveResponsible(), 'the task follows the new head, with nothing copied');
    }

    public function testCargoHasNoHolderWithoutAnActiveManager(): void
    {
        $maths = $this->unit('Matematicas');
        self::assertSame([], (new CargoResponsibility($maths))->holders(), 'no manager, no holder');

        $maths->setManager($this->user('Ana', active: false));
        self::assertSame([], (new CargoResponsibility($maths))->holders(), 'an inactive manager holds nothing');
    }

    public function testPersonIsAFixedSnapshot(): void
    {
        $ana = $this->user('Ana');
        $responsibility = new PersonResponsibility($ana);

        self::assertSame([$ana], $responsibility->holders());
        self::assertTrue($responsibility->isHeldBy($ana));
        self::assertSame('Ana', $responsibility->label());
    }

    public function testRoleHoldersAreItsActiveMembers(): void
    {
        $role = (new Role())->setCode('teacher')->setName('Docente');
        $active = $this->user('Ana');
        $inactive = $this->user('Beto', active: false);
        // addAssignedRole only touches the User side; populate the inverse collection directly.
        (new \ReflectionProperty(Role::class, 'users'))->setValue($role, new ArrayCollection([$active, $inactive]));

        $responsibility = new RoleResponsibility($role);

        self::assertSame([$active], $responsibility->holders());
        self::assertTrue($responsibility->isHeldBy($active));
        self::assertFalse($responsibility->isHeldBy($inactive), 'an inactive member does not hold it');
    }

    public function testDelegationOverridesTheStructuralResponsibility(): void
    {
        $head = $this->user('Ana');
        $delegatee = $this->user('Beto');
        $maths = $this->unit('Matematicas')->setManager($head);
        $task = $this->task()
            ->setResponsibility(new CargoResponsibility($maths))
            ->setDelegatedTo($delegatee);

        self::assertTrue($task->isOwnedBy($delegatee));
        self::assertFalse($task->isOwnedBy($head), 'once delegated, the head no longer owns it');
        self::assertSame($delegatee, $task->resolveResponsible());
    }

    public function testFallsBackToTheAssigneeWhenNoResponsibilityIsSet(): void
    {
        $ana = $this->user('Ana');
        $task = $this->task()->setAssignedUser($ana);

        self::assertTrue($task->isOwnedBy($ana), 'legacy rows without a responsibility still resolve via the assignee');
        self::assertSame($ana, $task->resolveResponsible());
    }
}
