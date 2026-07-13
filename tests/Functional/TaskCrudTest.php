<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\NonLectiveDay;
use App\Entity\Role;
use App\Entity\Task;
use App\Entity\TaskResponsibility;
use App\Entity\Unit;
use App\Entity\User;
use App\Enum\TaskType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Creating/editing tasks: a user can create tasks for themselves (and their subordinates), the
 * creator is recorded, and an unrelated user cannot edit someone else's task.
 */
final class TaskCrudTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function user(string $email, ?Unit $unit = null): User
    {
        $user = (new User())->setFullName(ucfirst(explode('@', $email)[0]).' Test')->setEmail($email);
        if (null !== $unit) {
            $user->setUnit($unit);
        }
        $this->em->persist($user);

        return $user;
    }

    public function testNewTaskFormRenders(): void
    {
        $unit = (new Unit())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $user = $this->user('jefa@centro.test', $unit);
        $this->em->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/tareas/nueva');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    public function testCreateTaskRecordsCreatorAndResponsibility(): void
    {
        $unit = (new Unit())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $teacherRole = (new Role())->setCode('teacher')->setName('Docente')->setPerDepartment(true);
        $this->em->persist($teacherRole);
        // The creator is a teacher in the department, so "profesor de Matemáticas" resolves to them.
        $creator = $this->user('jefa@centro.test', $unit);
        $creator->addAssignedRole($teacherRole);
        $this->em->flush();
        $roleId = (int) $teacherRole->getId();
        $unitId = (int) $unit->getId();
        $this->client->loginUser($creator);

        $crawler = $this->client->request('GET', '/tareas/nueva');
        $form = $crawler->selectButton('Crear tarea')->form();
        $form['task_form[title]'] = 'Preparar la evaluación';
        $form['task_form[dueDate]'] = '2026-09-15';
        $form['task_form[responsibilityRole]'] = (string) $roleId;
        $form['task_form[responsibilityUnit]'] = (string) $unitId;
        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->em->clear();
        $task = $this->em->getRepository(Task::class)->findOneBy(['title' => 'Preparar la evaluación']);
        self::assertNotNull($task);
        self::assertSame($creator->getId(), $task->getCreatedBy()?->getId());
        self::assertNotNull($task->getResponsibility());
        self::assertSame($roleId, $task->getResponsibility()->getRole()->getId());
        self::assertSame($unitId, $task->getResponsibility()->getUnit()?->getId());
    }

    public function testCannotCreateTaskDueOnAWeekend(): void
    {
        $unit = (new Unit())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $creator = $this->user('jefa@centro.test', $unit);
        $this->em->flush();
        $this->client->loginUser($creator);

        $crawler = $this->client->request('GET', '/tareas/nueva');
        $form = $crawler->selectButton('Crear tarea')->form();
        $form['task_form[title]'] = 'Tarea en sábado';
        $form['task_form[dueDate]'] = '2026-07-11'; // Saturday
        $this->client->submit($form);

        // Invalid submit: the form is redisplayed with the error (HTTP 422) and nothing is persisted.
        // The phrase is unique to the validation error (not the field's help text).
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('no puede caer en fin de semana', (string) $this->client->getResponse()->getContent());
        self::assertNull($this->em->getRepository(Task::class)->findOneBy(['title' => 'Tarea en sábado']));
    }

    public function testCannotCreateTaskDueOnARegisteredHoliday(): void
    {
        $unit = (new Unit())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $creator = $this->user('jefa@centro.test', $unit);
        // A Monday marked as a non-teaching day.
        $this->em->persist((new NonLectiveDay())->setDate(new \DateTimeImmutable('2026-07-13'))->setDescription('Fiesta local'));
        $this->em->flush();
        $this->client->loginUser($creator);

        $crawler = $this->client->request('GET', '/tareas/nueva');
        $form = $crawler->selectButton('Crear tarea')->form();
        $form['task_form[title]'] = 'Tarea en festivo';
        $form['task_form[dueDate]'] = '2026-07-13';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('no puede caer en fin de semana', (string) $this->client->getResponse()->getContent());
        self::assertNull($this->em->getRepository(Task::class)->findOneBy(['title' => 'Tarea en festivo']));
    }

    public function testCanChangeATasksResponsibilityRole(): void
    {
        $unit = (new Unit())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $direction = (new Role())->setCode('direction')->setName('Dirección');
        $ccp = (new Role())->setCode('ccp')->setName('Coordinación pedagógica');
        $this->em->persist($direction);
        $this->em->persist($ccp);
        $creator = $this->user('director@centro.test', $unit);
        // A centre-wide responsibility to start with (no department).
        $task = new Task('Acta de reunión', '2025-2026', new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE);
        $task->setResponsibility(new TaskResponsibility($direction, null))->setCreatedBy($creator);
        $this->em->persist($task);
        $this->em->flush();
        $ccpId = (int) $ccp->getId();

        $this->client->loginUser($creator);
        $crawler = $this->client->request('GET', '/tareas/'.$task->getId().'/editar');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[name="task_form[responsibilityRole]"]');
        $form = $crawler->selectButton('Guardar')->form();
        $form['task_form[responsibilityRole]'] = (string) $ccpId;
        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->em->clear();
        $reloaded = $this->em->getRepository(Task::class)->find($task->getId());
        self::assertNotNull($reloaded);
        self::assertSame($ccpId, $reloaded->getResponsibility()?->getRole()->getId());
    }

    public function testSuperiorCanWorkOnASubordinatesTask(): void
    {
        // studies is the parent of maths, so the head of studies is a superior of a maths teacher.
        $studies = (new Unit())->setCode('studies')->setName('Jefatura de estudios');
        $maths = (new Unit())->setCode('maths')->setName('Matemáticas')->setParent($studies);
        $this->em->persist($studies);
        $this->em->persist($maths);
        $headStudies = $this->user('jefatura@centro.test', $studies);
        $studies->setManager($headStudies);
        $teacher = $this->user('profe@centro.test', $maths);
        // A task owned by the teacher — the head of studies is neither its assignee nor a role holder.
        $task = new Task('Acta', '2025-2026', new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE);
        $task->setUnit($maths)->setAssignedUser($teacher)->setCreatedBy($teacher);
        $this->em->persist($task);
        $this->em->flush();

        $this->client->loginUser($headStudies);
        $crawler = $this->client->request('GET', '/tareas/'.$task->getId());
        self::assertResponseIsSuccessful();
        // The progress action is offered to the superior, proving they may work on it.
        self::assertSelectorExists('form[action$="/accion/complete"]');
        $this->client->submit($crawler->filter('form[action$="/accion/complete"]')->form());

        self::assertResponseRedirects();
        $this->em->clear();
        $reloaded = $this->em->getRepository(Task::class)->find($task->getId());
        self::assertSame('done', $reloaded?->getStatus());
    }

    public function testLateralUserIsNeitherSuperiorNorCanReachTheTask(): void
    {
        // Two sibling departments: a teacher in one is not a superior of a task in the other, so
        // widening canWorkOn to superiors must not leak a lateral colleague's task to them.
        $studies = (new Unit())->setCode('studies')->setName('Jefatura de estudios');
        $maths = (new Unit())->setCode('maths')->setName('Matemáticas')->setParent($studies);
        $language = (new Unit())->setCode('language')->setName('Lengua')->setParent($studies);
        $this->em->persist($studies);
        $this->em->persist($maths);
        $this->em->persist($language);
        $mathsTeacher = $this->user('mates@centro.test', $maths);
        $languageTeacher = $this->user('lengua@centro.test', $language);
        $task = new Task('Acta', '2025-2026', new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE);
        $task->setUnit($maths)->setAssignedUser($mathsTeacher)->setCreatedBy($mathsTeacher);
        $this->em->persist($task);
        $this->em->flush();

        // The language teacher is neither the assignee, a role holder, nor a superior of maths.
        $this->client->loginUser($languageTeacher);
        $this->client->request('GET', '/tareas/'.$task->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testSuperiorCanDelegateToASubordinate(): void
    {
        // A head who manages their own department and a member in it — the head is that member's superior.
        $dept = (new Unit())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($dept);
        $headRole = (new Role())->setCode('head_dept')->setName('Jefatura de departamento')->setPerDepartment(true);
        $this->em->persist($headRole);
        $boss = $this->user('jefa@centro.test', $dept);
        $dept->setManager($boss);
        $member = $this->user('profe@centro.test', $dept);
        // A department task ("jefatura de departamento de Matemáticas"), which the head delegates to a member.
        $task = new Task('Memoria', '2025-2026', new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE);
        $task->setUnit($dept)->setResponsibility(new TaskResponsibility($headRole, $dept))->setCreatedBy($boss);
        $this->em->persist($task);
        $this->em->flush();
        $memberId = (int) $member->getId();

        $this->client->loginUser($boss);
        $crawler = $this->client->request('GET', '/tareas/'.$task->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('select[name="delegatedTo"]');
        $form = $crawler->filter('form[action$="/delegar"]')->form();
        $form['delegatedTo'] = (string) $memberId;
        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->em->clear();
        $reloaded = $this->em->getRepository(Task::class)->find($task->getId());
        self::assertNotNull($reloaded);
        self::assertSame($memberId, $reloaded->getDelegatedTo()?->getId(), 'the task is now delegated to the subordinate');
    }

    public function testUnrelatedUserCannotEditTask(): void
    {
        $unit = (new Unit())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $creator = $this->user('jefa@centro.test', $unit);
        $task = new Task('Memoria', '2025-2026', new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE);
        $task->setUnit($unit)->setCreatedBy($creator);
        $this->em->persist($task);
        $stranger = $this->user('otro@centro.test');
        $this->em->flush();

        $this->client->loginUser($stranger);
        $this->client->request('GET', '/tareas/'.$task->getId().'/editar');

        self::assertResponseStatusCodeSame(403);
    }
}
