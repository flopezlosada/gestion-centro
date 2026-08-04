<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Agenda\AgendaEntry;
use App\Agenda\PersonalAgenda;
use App\Entity\Department;
use App\Entity\Role;
use App\Entity\Task;
use App\Entity\TaskResponsibility;
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

    public function testADelegatedTaskLeavesTheDelegatorAndEntersTheDelegatee(): void
    {
        $boss = (new User())->setFullName('Jefa')->setEmail('jefa@centro.test');
        $sub = (new User())->setFullName('Sub')->setEmail('sub@centro.test');
        $this->em->persist($boss);
        $this->em->persist($sub);
        // Assigned to the boss, delegated down to the sub: the agenda is "what I must do", so it must
        // leave the delegator and appear for the delegatee (mirrors Task::isOwnedBy in the query).
        $this->assignedTask($boss, $this->today, TaskStatus::PENDING)->setDelegatedTo($sub);
        $this->em->flush();

        $bossToday = $this->taskTitles($this->agenda->bucketsFor($boss, $this->today)['today']);
        $subToday = $this->taskTitles($this->agenda->bucketsFor($sub, $this->today)['today']);

        self::assertNotContains('Tarea pending', $bossToday, 'una tarea que delegué sale de mi agenda');
        self::assertContains('Tarea pending', $subToday, 'y entra en la agenda del delegado');
    }

    /**
     * Sin persona concreta, la tarea es de quien tenga el rol: entra en su agenda por la
     * responsabilidad estructural, no por haber sido asignada a mano.
     */
    public function testATaskWithNoAssigneeReachesTheHolderOfItsRole(): void
    {
        $role = (new Role())->setCode('ccp')->setName('Coordinación pedagógica');
        $this->em->persist($role);
        $holder = (new User())->setFullName('Marta Coordina')->setEmail('coord@centro.test')->addAssignedRole($role);
        $this->em->persist($holder);
        $task = (new Task('Acta de la CCP', $this->schoolYear, $this->today))
            ->setResponsibility(new TaskResponsibility($role))
            ->setStatus(TaskStatus::PENDING);
        $this->em->persist($task);
        $this->em->flush();

        self::assertContains('Acta de la CCP', $this->taskTitles($this->agenda->bucketsFor($holder, $this->today)['today']));
    }

    /**
     * Y en cuanto hay una persona concreta, deja de ser de los demás titulares del rol.
     *
     * Es la regresión que escondía la consulta vieja: leía el rol sin exigir que NO hubiera asignado, de
     * modo que una tarea de "Coordinación pedagógica" ya asignada a Marta seguía apareciendo en la
     * agenda de Sara, que también tiene el rol. La ficha de esa misma tarea, que pregunta a
     * {@see \App\Entity\Task::isOwnedBy()}, le decía a Sara que no era suya: dos pantallas de la misma
     * aplicación contestando cosas distintas sobre la misma fila.
     */
    public function testARoleTaskAlreadyAssignedIsNotInTheOtherHoldersAgenda(): void
    {
        $role = (new Role())->setCode('ccp')->setName('Coordinación pedagógica');
        $this->em->persist($role);
        $assignee = (new User())->setFullName('Marta Coordina')->setEmail('coord@centro.test')->addAssignedRole($role);
        $colleague = (new User())->setFullName('Sara Colega')->setEmail('colega@centro.test')->addAssignedRole($role);
        $this->em->persist($assignee);
        $this->em->persist($colleague);
        $task = (new Task('Acta de la CCP', $this->schoolYear, $this->today))
            ->setResponsibility(new TaskResponsibility($role))
            ->setAssignedUser($assignee)
            ->setStatus(TaskStatus::PENDING);
        $this->em->persist($task);
        $this->em->flush();

        self::assertContains('Acta de la CCP', $this->taskTitles($this->agenda->bucketsFor($assignee, $this->today)['today']));
        self::assertNotContains(
            'Acta de la CCP',
            $this->taskTitles($this->agenda->bucketsFor($colleague, $this->today)['today']),
            'con responsable concreto, la tarea deja de ser de los demás titulares del rol',
        );
    }

    /**
     * Un rol de departamento solo es tuyo en el TUYO: la consulta reproduce el filtro de
     * {@see \App\Entity\TaskResponsibility::holders()} y no reparte la memoria de Matemáticas entre
     * todas las jefaturas de departamento del centro.
     */
    public function testAPerDepartmentRoleTaskStaysInItsOwnDepartment(): void
    {
        $maths = (new Department())->setCode('maths')->setName('Matemáticas');
        $arts = (new Department())->setCode('arts')->setName('Plástica');
        $role = (new Role())->setCode('head_dept')->setName('Jefatura de departamento')->setPerDepartment(true);
        array_map($this->em->persist(...), [$maths, $arts, $role]);
        $mathsHead = (new User())->setFullName('María Mates')->setEmail('mates@centro.test')->setUnit($maths)->addAssignedRole($role);
        $artsHead = (new User())->setFullName('Ana Plástica')->setEmail('plastica@centro.test')->setUnit($arts)->addAssignedRole($role);
        $this->em->persist($mathsHead);
        $this->em->persist($artsHead);
        $task = (new Task('Memoria de Matemáticas', $this->schoolYear, $this->today))
            ->setResponsibility(new TaskResponsibility($role, $maths))
            ->setUnit($maths)
            ->setStatus(TaskStatus::PENDING);
        $this->em->persist($task);
        $this->em->flush();

        self::assertContains('Memoria de Matemáticas', $this->taskTitles($this->agenda->bucketsFor($mathsHead, $this->today)['today']));
        self::assertNotContains(
            'Memoria de Matemáticas',
            $this->taskTitles($this->agenda->bucketsFor($artsHead, $this->today)['today']),
            'la memoria de Matemáticas no es de la jefa de Plástica',
        );
    }
}
