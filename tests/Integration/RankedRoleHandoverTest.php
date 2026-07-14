<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Role;
use App\Entity\Task;
use App\Entity\TaskResponsibility;
use App\Entity\Department;
use App\Entity\User;
use App\Enum\TaskType;
use App\Service\RankedRoleHandover;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Taking over a hierarchy post pulls its OPEN, CURRENT-course tasks to the new holder — and only those:
 * closed tasks, past courses and other roles stay put.
 */
final class RankedRoleHandoverTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RankedRoleHandover $handover;
    private \DateTimeImmutable $today;
    private string $year;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->handover = self::getContainer()->get(RankedRoleHandover::class);
        $this->today = new \DateTimeImmutable('2026-03-01');
        $this->year = SchoolYear::current($this->today);
    }

    private function task(string $title, string $year, ?TaskResponsibility $responsibility, User $assignee, string $status = 'pending'): Task
    {
        $task = new Task($title, $year, new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE);
        $task->setResponsibility($responsibility)->setAssignedUser($assignee)->setStatus($status);
        if (null !== $responsibility?->getUnit()) {
            $task->setUnit($responsibility->getUnit());
        }
        $this->em->persist($task);

        return $task;
    }

    public function testDepartmentHeadHandoverMovesOnlyOpenCurrentYearTasksOfThatPost(): void
    {
        $maths = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($maths);
        $headRole = (new Role())->setCode('head_dept')->setName('Jefatura de departamento')->setPerDepartment(true)->setHierarchyLevel(10);
        $teacherRole = (new Role())->setCode('teacher')->setName('Docente')->setPerDepartment(true);
        $this->em->persist($headRole);
        $this->em->persist($teacherRole);

        $oldHead = (new User())->setFullName('María Saliente')->setEmail('maria@centro.test')->setUnit($maths)->addAssignedRole($headRole);
        $newHead = (new User())->setFullName('José Entrante')->setEmail('jose@centro.test')->setUnit($maths);
        $this->em->persist($oldHead);
        $this->em->persist($newHead);

        $open = $this->task('Memoria de jefatura', $this->year, new TaskResponsibility($headRole, $maths), $oldHead);
        $closed = $this->task('Acta ya validada', $this->year, new TaskResponsibility($headRole, $maths), $oldHead, 'validated');
        $pastYear = $this->task('Memoria del año pasado', '2020-2021', new TaskResponsibility($headRole, $maths), $oldHead);
        $otherRole = $this->task('Tarea de docente', $this->year, new TaskResponsibility($teacherRole, $maths), $oldHead);
        $this->em->flush();

        $moved = $this->handover->toNewHolder($newHead, $headRole, $this->today);
        $this->em->flush();
        $this->em->clear();

        self::assertSame(1, $moved, 'only the open current-year jefatura task moves');
        self::assertSame($newHead->getId(), $this->em->getRepository(Task::class)->find($open->getId())?->getAssignedUser()?->getId());
        self::assertSame($oldHead->getId(), $this->em->getRepository(Task::class)->find($closed->getId())?->getAssignedUser()?->getId(), 'closed tasks stay');
        self::assertSame($oldHead->getId(), $this->em->getRepository(Task::class)->find($pastYear->getId())?->getAssignedUser()?->getId(), 'past courses stay');
        self::assertSame($oldHead->getId(), $this->em->getRepository(Task::class)->find($otherRole->getId())?->getAssignedUser()?->getId(), 'other roles stay');
    }

    public function testCentreWideHandoverMovesCentreTasksAndIgnoresDepartmentScope(): void
    {
        $directionRole = (new Role())->setCode('direction')->setName('Dirección')->setHierarchyLevel(40);
        $this->em->persist($directionRole);
        $oldDirector = (new User())->setFullName('Ana Saliente')->setEmail('ana@centro.test')->addAssignedRole($directionRole);
        $newDirector = (new User())->setFullName('Berta Entrante')->setEmail('berta@centro.test');
        $this->em->persist($oldDirector);
        $this->em->persist($newDirector);

        $centreTask = $this->task('Plan de centro', $this->year, new TaskResponsibility($directionRole, null), $oldDirector);
        $this->em->flush();

        $moved = $this->handover->toNewHolder($newDirector, $directionRole, $this->today);
        $this->em->flush();
        $this->em->clear();

        self::assertSame(1, $moved);
        self::assertSame($newDirector->getId(), $this->em->getRepository(Task::class)->find($centreTask->getId())?->getAssignedUser()?->getId());
    }

    public function testNonRankedRoleDoesNotHandOver(): void
    {
        $tutorRole = (new Role())->setCode('tutor')->setName('Tutor/a')->setPerDepartment(true);
        $this->em->persist($tutorRole);
        $maths = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($maths);
        $holder = (new User())->setFullName('Nuevo Tutor')->setEmail('tutor@centro.test')->setUnit($maths);
        $this->em->persist($holder);

        self::assertSame(0, $this->handover->toNewHolder($holder, $tutorRole, $this->today), 'a role without rank never hands over');
    }
}
