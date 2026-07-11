<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Role;
use App\Entity\Task;
use App\Entity\Unit;
use App\Entity\User;
use App\Enum\TaskType;
use App\Repository\NotificationRepository;
use App\Service\TaskReminderNotifier;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The reminder engine must notify the assignee ahead of the deadline and escalate overdue, still-open
 * tasks up the chain of command.
 */
final class TaskReminderNotifierTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TaskReminderNotifier $notifier;
    private NotificationRepository $notifications;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->notifier = self::getContainer()->get(TaskReminderNotifier::class);
        $this->notifications = self::getContainer()->get(NotificationRepository::class);
    }

    private function user(string $email): User
    {
        $user = (new User())->setFullName($email)->setEmail($email);
        $this->em->persist($user);

        return $user;
    }

    private function task(\DateTimeImmutable $due, Unit $unit): Task
    {
        $task = new Task('Memoria', SchoolYear::current($due), $due, TaskType::WITH_DELIVERABLE);
        $task->setUnit($unit);
        $this->em->persist($task);

        return $task;
    }

    public function testAssigneeIsRemindedFifteenDaysBefore(): void
    {
        $today = new \DateTimeImmutable('2026-01-10');
        $teacher = $this->user('profe@centro.test');
        $unit = (new Unit())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $this->task($today->modify('+15 days'), $unit)->setAssignedUser($teacher);
        $this->em->flush();

        $created = $this->notifier->sendDue($today);

        self::assertSame(1, $created);
        $notice = $this->notifications->findRecentFor($teacher)[0] ?? null;
        self::assertNotNull($notice);
        self::assertSame('task.reminder', $notice->getKind());
    }

    public function testOverdueTaskIsEscalatedToTheManager(): void
    {
        $today = new \DateTimeImmutable('2026-01-10');
        $head = $this->user('jefa@centro.test');
        $teacher = $this->user('profe@centro.test');
        $unit = (new Unit())->setCode('maths')->setName('Matemáticas')->setManager($head);
        $this->em->persist($unit);
        // Overdue by exactly one day, still pending.
        $this->task($today->modify('-1 day'), $unit)->setAssignedUser($teacher);
        $this->em->flush();

        $this->notifier->sendDue($today);

        $notices = $this->notifications->findRecentFor($head);
        self::assertCount(1, $notices);
        self::assertSame('task.escalation', $notices[0]->getKind());
    }

    public function testNothingIsSentWhenNoTaskMatches(): void
    {
        $today = new \DateTimeImmutable('2026-01-10');
        $unit = (new Unit())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        // Due far away and not overdue → no reminder, no escalation.
        $this->task($today->modify('+40 days'), $unit)->setAssignedUser($this->user('profe@centro.test'));
        $this->em->flush();

        self::assertSame(0, $this->notifier->sendDue($today));
    }

    public function testOverdueTaskWithoutUnitDoesNotCrashAndEscalatesToNobody(): void
    {
        $today = new \DateTimeImmutable('2026-01-10');
        $unit = (new Unit())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        // Overdue, but the task has no unit → no chain of command to escalate to.
        $task = new Task('Sin unidad', SchoolYear::current($today), $today->modify('-1 day'), TaskType::SIMPLE);
        $task->setAssignedUser($this->user('profe@centro.test'));
        $this->em->persist($task);
        $this->em->flush();

        self::assertSame(0, $this->notifier->sendDue($today));
    }

    public function testUnassignedTaskProducesNoReminder(): void
    {
        $today = new \DateTimeImmutable('2026-01-10');
        $unit = (new Unit())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        // Due in 7 days but with neither an assigned user nor role.
        $this->task($today->modify('+7 days'), $unit);
        $this->em->flush();

        self::assertSame(0, $this->notifier->sendDue($today));
    }

    public function testRoleWithOnlyInactiveHolderProducesNoReminder(): void
    {
        $today = new \DateTimeImmutable('2026-01-10');
        $unit = (new Unit())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);

        $role = (new Role())->setCode('coordination')->setName('Coordinación docente');
        $this->em->persist($role);
        $inactive = $this->user('baja@centro.test')->setActive(false)->addAssignedRole($role);
        $this->em->persist($inactive);

        $this->task($today->modify('+15 days'), $unit)->setAssignedRole($role);
        $this->em->flush();

        self::assertSame(0, $this->notifier->sendDue($today), 'an inactive role holder is not a recipient');
    }
}
