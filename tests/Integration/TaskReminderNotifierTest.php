<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Role;
use App\Entity\Task;
use App\Entity\Department;
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

    private function task(\DateTimeImmutable $due, Department $unit): Task
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
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
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
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        // The head of the department holds a per-department ranked role, so they command it and are the
        // nearest superior to escalate an overdue maths task to.
        $headRole = (new Role())->setCode('head_dept')->setName('Jefatura de departamento')->setPerDepartment(true)->setHierarchyLevel(10);
        $this->em->persist($headRole);
        $head = $this->user('jefa@centro.test');
        $head->setUnit($unit)->addAssignedRole($headRole);
        $teacher = $this->user('profe@centro.test');
        $teacher->setUnit($unit);
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
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        // Due far away and not overdue → no reminder, no escalation.
        $this->task($today->modify('+40 days'), $unit)->setAssignedUser($this->user('profe@centro.test'));
        $this->em->flush();

        self::assertSame(0, $this->notifier->sendDue($today));
    }

    public function testOverdueTaskWithoutUnitDoesNotCrashAndEscalatesToNobody(): void
    {
        $today = new \DateTimeImmutable('2026-01-10');
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
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
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        // Due in 7 days but with neither an assigned user nor role.
        $this->task($today->modify('+7 days'), $unit);
        $this->em->flush();

        self::assertSame(0, $this->notifier->sendDue($today));
    }

    /**
     * A delegated task is the DELEGATEE's to do, so both the automatic reminder and the manual nudge go
     * to them — not to the titular who handed it over, who was getting the deadline warnings for work
     * that was no longer theirs.
     */
    public function testReminderGoesToTheDelegateeNotTheTitular(): void
    {
        $today = new \DateTimeImmutable('2026-01-10');
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $titular = $this->user('jefa@centro.test');
        $delegatee = $this->user('profe@centro.test');
        $this->task($today->modify('+7 days'), $unit)->setAssignedUser($titular)->setDelegatedTo($delegatee);
        $this->em->flush();

        self::assertSame(1, $this->notifier->sendDue($today));
        self::assertCount(1, $this->notifications->findRecentFor($delegatee));
        self::assertCount(0, $this->notifications->findRecentFor($titular), 'quien delegó ya no es quien debe hacerla');
    }

    /** The manual nudge reaches whoever owes the work, with the same kind the nightly engine uses. */
    public function testNudgeNotifiesTheResponsible(): void
    {
        $now = new \DateTimeImmutable('2026-01-10 13:40');
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $teacher = $this->user('profe@centro.test');
        // Already overdue: exactly the case the automatic reminders no longer cover.
        $task = $this->task($now->modify('-3 days'), $unit)->setAssignedUser($teacher);
        $this->em->flush();

        $notified = $this->notifier->nudge($task, $now);

        self::assertSame([$teacher], $notified);
        $notice = $this->notifications->findRecentFor($teacher)[0] ?? null;
        self::assertNotNull($notice);
        self::assertSame(TaskReminderNotifier::REMINDER_KIND, $notice->getKind());
    }

    /**
     * At most one nudge per person and day: a button that can be pressed ten times would be ten e-mails
     * and ten push notifications to the same person.
     */
    public function testSecondNudgeTheSameDayIsHeldBack(): void
    {
        $now = new \DateTimeImmutable('2026-01-10 13:40');
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $teacher = $this->user('profe@centro.test');
        $task = $this->task($now->modify('-3 days'), $unit)->setAssignedUser($teacher);
        $this->em->flush();

        $this->notifier->nudge($task, $now);
        $again = $this->notifier->nudge($task, $now->modify('+2 hours'));

        self::assertSame([], $again, 'ya se avisó hoy');
        self::assertCount(1, $this->notifications->findRecentFor($teacher), 'un solo aviso, no dos');
        self::assertNotNull($this->notifier->nudgedTodayAt($task, $now), 'la ficha lo puede decir en pantalla');
    }

    /** The cap is per DAY: tomorrow the same task can be nudged again. */
    public function testNudgeIsAvailableAgainTheNextDay(): void
    {
        $now = new \DateTimeImmutable('2026-01-10 13:40');
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $teacher = $this->user('profe@centro.test');
        $task = $this->task($now->modify('-3 days'), $unit)->setAssignedUser($teacher);
        $this->em->flush();

        $this->notifier->nudge($task, $now);
        $tomorrow = $now->modify('+1 day');

        self::assertNull($this->notifier->nudgedTodayAt($task, $tomorrow));
        self::assertSame([$teacher], $this->notifier->nudge($task, $tomorrow));
    }

    /**
     * The nightly reminder counts towards the cap too: both share the kind on purpose, so nobody is told
     * twice in one day about the same task through two different routes.
     */
    public function testNudgeIsHeldBackWhenTheEngineAlreadyRemindedToday(): void
    {
        $today = new \DateTimeImmutable('2026-01-10 09:00');
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $teacher = $this->user('profe@centro.test');
        // Due in exactly 7 days → the engine reminds today.
        $task = $this->task($today->modify('+7 days'), $unit)->setAssignedUser($teacher);
        $this->em->flush();

        self::assertSame(1, $this->notifier->sendDue($today));

        self::assertSame([], $this->notifier->nudge($task, $today->modify('+4 hours')), 'el cron ya avisó hoy');
        self::assertCount(1, $this->notifications->findRecentFor($teacher));
    }

    /** With nobody to do it there is nobody to nudge, and the caller must be able to tell that apart. */
    public function testNudgeOnAnUnassignedTaskReachesNobody(): void
    {
        $now = new \DateTimeImmutable('2026-01-10 13:40');
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $task = $this->task($now->modify('-3 days'), $unit);
        $this->em->flush();

        self::assertSame([], $this->notifier->nudge($task, $now));
        self::assertSame([], $this->notifier->nudgeRecipients($task));
        self::assertNull($this->notifier->nudgedTodayAt($task, $now), 'nadie a quien avisar no es "ya avisado"');
    }

    public function testRoleWithOnlyInactiveHolderProducesNoReminder(): void
    {
        $today = new \DateTimeImmutable('2026-01-10');
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);

        $role = (new Role())->setCode('head_dept')->setName('Jefatura de departamento');
        $this->em->persist($role);
        $inactive = $this->user('baja@centro.test')->setActive(false)->addAssignedRole($role);
        $this->em->persist($inactive);

        $this->task($today->modify('+15 days'), $unit)->setAssignedRole($role);
        $this->em->flush();

        self::assertSame(0, $this->notifier->sendDue($today), 'an inactive role holder is not a recipient');
    }
}
