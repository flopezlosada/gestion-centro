<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Role;
use App\Entity\Task;
use App\Entity\Unit;
use App\Entity\User;
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
        $teacherRole = (new Role())->setCode('teacher')->setName('Docente');
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

    public function testHomeDashboardRenders(): void
    {
        $s = $this->seed();
        $this->client->loginUser($s['admin']);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('aside.sidebar');
        self::assertSelectorExists('.worklist-stats');
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
        self::assertSelectorTextContains('h2', 'Histórico de actividad');
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
}
