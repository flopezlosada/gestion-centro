<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Agenda\AgendaEntry;
use App\Agenda\PersonalAgenda;
use App\Entity\Task;
use App\Entity\User;
use App\Support\TaskStatus;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A finished task must not keep showing up as pending in the personal agenda, and a cancelled task
 * must not show at all. The agenda buckets by lifecycle status (Finalizada = hecha) on top of the
 * assignee's progress checkbox, not only by the checkbox — the regression these tests pin down.
 */
final class PersonalAgendaTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PersonalAgenda $agenda;
    private \DateTimeImmutable $today;
    private string $schoolYear;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->agenda = self::getContainer()->get(PersonalAgenda::class);
        // The same reference day the controller uses, so the school year lines up with findAgendaFor.
        $this->today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Madrid'));
        $this->schoolYear = SchoolYear::current($this->today);
    }

    private function assignedTask(User $user, \DateTimeImmutable $dueDate, string $status): Task
    {
        $task = (new Task('Tarea '.$status, $this->schoolYear, $dueDate))
            ->setAssignedUser($user)
            ->setStatus($status);
        $this->em->persist($task);

        return $task;
    }

    private function user(): User
    {
        $user = (new User())->setFullName('Profe Test')->setEmail('profe@centro.test');
        $this->em->persist($user);

        return $user;
    }

    /**
     * @param AgendaEntry[] $entries
     *
     * @return string[] the titles of the task entries in that bucket
     */
    private function taskTitles(array $entries): array
    {
        return array_values(array_map(
            static fn (AgendaEntry $e): string => (string) $e->task?->getTitle(),
            array_filter($entries, static fn (AgendaEntry $e): bool => AgendaEntry::KIND_TASK === $e->kind),
        ));
    }

    public function testACancelledTaskDoesNotShowInTheAgendaAtAll(): void
    {
        $user = $this->user();
        // Due today so, were it not excluded, it would land squarely in the "today" bucket.
        $this->assignedTask($user, $this->today, TaskStatus::CANCELLED);
        $this->em->flush();

        $buckets = $this->agenda->bucketsFor($user, $this->today);

        $all = array_merge(...array_values($buckets));
        self::assertNotContains('Tarea cancelled', $this->taskTitles($all), 'una tarea cancelada no aparece en la agenda');
    }

    public function testAValidatedTaskGoesToDoneEvenWithAnOverdueDeadlineAndNoCheckbox(): void
    {
        $user = $this->user();
        // Past deadline + checkbox unmarked: before the fix this fell into "Vencidas" as if pending.
        $this->assignedTask($user, $this->today->modify('-3 days'), TaskStatus::VALIDATED);
        $this->em->flush();

        $buckets = $this->agenda->bucketsFor($user, $this->today);

        self::assertContains('Tarea validated', $this->taskTitles($buckets['done']), 'una finalizada cuenta como hecha');
        self::assertNotContains('Tarea validated', $this->taskTitles($buckets['overdue']), 'una finalizada no vuelve como vencida');
    }

    public function testAPendingOverdueTaskStillShowsAsOverdue(): void
    {
        $user = $this->user();
        $this->assignedTask($user, $this->today->modify('-3 days'), TaskStatus::PENDING);
        $this->em->flush();

        $buckets = $this->agenda->bucketsFor($user, $this->today);

        self::assertContains('Tarea pending', $this->taskTitles($buckets['overdue']), 'una pendiente vencida sigue saliendo');
    }
}
