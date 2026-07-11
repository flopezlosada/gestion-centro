<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Role;
use App\Entity\Task;
use App\Entity\Unit;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use App\Enum\TaskType;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The authenticated shell and the task read views must render, and the per-object activity history
 * must appear only for superiors/admins.
 */
final class DesignPagesTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function user(string $email, ?Role $role = null): User
    {
        $user = (new User())->setFullName(ucfirst(explode('@', $email)[0]).' Test')->setEmail($email);
        if (null !== $role) {
            $user->addAssignedRole($role);
        }
        $this->em->persist($user);

        return $user;
    }

    /**
     * @return array{task: Task, admin: User, teacher: User}
     */
    private function seed(): array
    {
        $adminRole = (new Role())->setCode('direction')->setName('Dirección')->setAdmin(true);
        // Read access to Tasks, but no admin and no unit management → can open the page, no history.
        $teacherRole = (new Role())->setCode('teacher')->setName('Docente')->setLevel(Area::TASK, PermissionLevel::READ);
        $this->em->persist($adminRole);
        $this->em->persist($teacherRole);

        $admin = $this->user('director@centro.test', $adminRole);
        $teacher = $this->user('profe@centro.test', $teacherRole);

        $maths = (new Unit())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($maths);

        $task = new Task('Memoria del departamento', SchoolYear::current(new \DateTimeImmutable()), new \DateTimeImmutable('2026-06-30'), TaskType::WITH_DELIVERABLE);
        $task->setUnit($maths)->setAssignedUser($teacher);
        $this->em->persist($task);
        $this->em->flush();

        return ['task' => $task, 'admin' => $admin, 'teacher' => $teacher];
    }

    public function testHomeAgendaShowsMyTasks(): void
    {
        $s = $this->seed();
        $this->client->loginUser($s['teacher']);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('aside.sidebar');
        self::assertSelectorTextContains('.agenda-item', 'Memoria del departamento');
    }

    public function testOneClickDoneTogglesTheTask(): void
    {
        $s = $this->seed();
        $this->client->loginUser($s['teacher']);

        $crawler = $this->client->request('GET', '/');
        $this->client->submit($crawler->filter('form.agenda-check')->first()->form());

        self::assertResponseRedirects();
        $this->em->clear();
        $reloaded = $this->em->getRepository(Task::class)->find($s['task']->getId());
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isCheckboxDone(), 'la casilla marca la tarea como hecha');
    }

    public function testTaskListShowsPlannedTasks(): void
    {
        $s = $this->seed();
        $this->client->loginUser($s['admin']);

        $this->client->request('GET', '/tareas');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Memoria del departamento');
    }

    public function testAdminSeesActivityHistoryOnTaskShow(): void
    {
        $s = $this->seed();
        $this->client->loginUser($s['admin']);

        $this->client->request('GET', '/tareas/'.$s['task']->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Histórico de actividad', (string) $this->client->getResponse()->getContent());
        self::assertSelectorExists('.obj-timeline');
    }

    public function testNonSuperiorDoesNotSeeActivityHistory(): void
    {
        $s = $this->seed();
        $this->client->loginUser($s['teacher']);

        $this->client->request('GET', '/tareas/'.$s['task']->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.obj-timeline');
    }

    public function testSuperiorNonAdminSeesActivityHistory(): void
    {
        $headRole = (new Role())->setCode('head_dept')->setName('Jefatura de departamento')->setLevel(Area::TASK, PermissionLevel::READ);
        $this->em->persist($headRole);
        $head = $this->user('jefa@centro.test', $headRole);

        $unit = (new Unit())->setCode('maths')->setName('Matemáticas')->setManager($head);
        $this->em->persist($unit);

        $task = new Task('Memoria del departamento', SchoolYear::current(new \DateTimeImmutable()), new \DateTimeImmutable('2026-06-30'), TaskType::WITH_DELIVERABLE);
        $task->setUnit($unit);
        $this->em->persist($task);
        $this->em->flush();

        $this->client->loginUser($head);
        $this->client->request('GET', '/tareas/'.$task->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.obj-timeline');
    }

    public function testAssigneeCanAdvanceTaskFromDetail(): void
    {
        $s = $this->seed();
        $this->client->loginUser($s['teacher']);

        $crawler = $this->client->request('GET', '/tareas/'.$s['task']->getId());
        $this->client->submit($crawler->filter('.task-actions form')->first()->form());

        self::assertResponseRedirects();
        $this->em->clear();
        $reloaded = $this->em->getRepository(Task::class)->find($s['task']->getId());
        self::assertNotNull($reloaded);
        self::assertSame('in_progress', $reloaded->getStatus(), 'el responsable avanza la tarea desde el detalle');
    }

    public function testNonSuperiorHasNoValidateActionOnSubmittedTask(): void
    {
        $s = $this->seed();
        $s['task']->setStatus('submitted');
        $this->em->flush();
        $this->client->loginUser($s['teacher']);

        $this->client->request('GET', '/tareas/'.$s['task']->getId());

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('accion/validate', (string) $this->client->getResponse()->getContent(), 'un no-superior no ve la acción de validar');
    }
}
