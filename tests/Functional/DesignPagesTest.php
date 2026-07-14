<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Role;
use App\Entity\Task;
use App\Entity\TaskResponsibility;
use App\Entity\Department;
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
        // A plain responsibility: no admin and no unit management → the assignee of the task, so it is
        // their own, but with no superiority they see no history.
        $teacherRole = (new Role())->setCode('teacher')->setName('Docente');
        $this->em->persist($adminRole);
        $this->em->persist($teacherRole);

        $admin = $this->user('director@centro.test', $adminRole);
        $teacher = $this->user('profe@centro.test', $teacherRole);

        $maths = (new Department())->setCode('maths')->setName('Matemáticas');
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

        $this->client->request('GET', '/agenda');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('aside.sidebar');
        self::assertSelectorTextContains('.agenda-item', 'Memoria del departamento');
    }

    public function testOneClickDoneTogglesTheTask(): void
    {
        $s = $this->seed();
        $this->client->loginUser($s['teacher']);

        $crawler = $this->client->request('GET', '/agenda');
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

    public function testOwnerSeesActivityHistory(): void
    {
        // The assignee is the task's owner and now sees its own history.
        $s = $this->seed();
        $this->client->loginUser($s['teacher']);

        $this->client->request('GET', '/tareas/'.$s['task']->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.obj-timeline');
    }

    public function testUnrelatedUserIsForbiddenFromTaskDetail(): void
    {
        $s = $this->seed();
        // An authenticated user unrelated to the task (not assignee, not superior): the detail is
        // scoped by the org chart just like the plan, so it is forbidden — not merely stripped of its
        // history.
        $role = (new Role())->setCode('lector')->setName('Lector');
        $this->em->persist($role);
        $reader = $this->user('lector@centro.test', $role);
        $this->em->flush();
        $this->client->loginUser($reader);

        $this->client->request('GET', '/tareas/'.$s['task']->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testSuperiorNonAdminSeesActivityHistory(): void
    {
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $headRole = (new Role())->setCode('head_dept')->setName('Jefatura de departamento')->setPerDepartment(true)->setHierarchyLevel(10);
        $this->em->persist($headRole);
        $head = $this->user('jefa@centro.test', $headRole)->setUnit($unit);

        $task = new Task('Memoria del departamento', SchoolYear::current(new \DateTimeImmutable()), new \DateTimeImmutable('2026-06-30'), TaskType::WITH_DELIVERABLE);
        // A jefatura task in Maths — the head of Maths owns it and may open its detail.
        $task->setUnit($unit)->setResponsibility(new TaskResponsibility($headRole, $unit));
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
        // La tarea lleva entregable: al entregar hay que adjuntar la referencia del documento.
        $s['task']->setRequiresDocument(true);
        $this->em->flush();
        $this->client->loginUser($s['teacher']);

        $crawler = $this->client->request('GET', '/tareas/'.$s['task']->getId());
        // Entregar adjunta la referencia del documento en el mismo paso.
        $form = $crawler->filter('.task-actions form')->first()->form();
        $form['reference'] = 'https://cloud.educa.madrid.org/memoria';
        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->em->clear();
        $reloaded = $this->em->getRepository(Task::class)->find($s['task']->getId());
        self::assertNotNull($reloaded);
        self::assertSame('submitted', $reloaded->getStatus(), 'el responsable entrega la tarea desde el detalle');
    }

    public function testNonSuperiorHasNoValidateActionOnSubmittedTask(): void
    {
        $s = $this->seed();
        $s['task']->setStatus('submitted');
        $this->em->flush();
        $this->client->loginUser($s['teacher']);

        $this->client->request('GET', '/tareas/'.$s['task']->getId());

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('accion/validate', $content, 'un no-superior no ve la acción de validar');
        self::assertStringNotContainsString('accion/reject', $content, 'un no-superior no ve la acción de devolver');
    }
}
