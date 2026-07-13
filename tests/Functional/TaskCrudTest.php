<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\NonLectiveDay;
use App\Entity\PersonResponsibility;
use App\Entity\Role;
use App\Entity\RoleResponsibility;
use App\Entity\Task;
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

    public function testCreateTaskRecordsCreatorAndAssignee(): void
    {
        $unit = (new Unit())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $creator = $this->user('jefa@centro.test', $unit);
        $this->em->flush();
        $this->client->loginUser($creator);

        $crawler = $this->client->request('GET', '/tareas/nueva');
        $form = $crawler->selectButton('Crear tarea')->form();
        $form['task_form[title]'] = 'Preparar la evaluación';
        $form['task_form[dueDate]'] = '2026-09-15';
        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->em->clear();
        $task = $this->em->getRepository(Task::class)->findOneBy(['title' => 'Preparar la evaluación']);
        self::assertNotNull($task);
        self::assertSame($creator->getId(), $task->getCreatedBy()?->getId());
        self::assertSame($creator->getId(), $task->getAssignedUser()?->getId());
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

    public function testEditingTaskKeepsItsResponsibleRole(): void
    {
        $unit = (new Unit())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $role = (new Role())->setCode('head_dept')->setName('Jefatura de departamento');
        $this->em->persist($role);
        $creator = $this->user('jefa@centro.test', $unit);
        // A task carrying BOTH a responsible role (structural, from a template) and a concrete
        // assignee — exactly the shape the fixtures create and the edit form used to destroy.
        $task = new Task('Acta de reunión', '2025-2026', new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE);
        $task->setUnit($unit)->setAssignedRole($role)->setAssignedUser($creator)->setCreatedBy($creator);
        $this->em->persist($task);
        $this->em->flush();

        $this->client->loginUser($creator);
        $crawler = $this->client->request('GET', '/tareas/'.$task->getId().'/editar');
        // A department head is not leadership: the role field is not even offered to them.
        self::assertSelectorNotExists('[name="task_form[assignedRole]"]');
        // Just save the edit (the deliverable/type is no longer an editable field).
        $this->client->submit($crawler->selectButton('Guardar')->form());

        self::assertResponseRedirects();
        $this->em->clear();
        $reloaded = $this->em->getRepository(Task::class)->find($task->getId());
        self::assertNotNull($reloaded);
        // The role must survive the edit (the whole point of the fix).
        self::assertSame($role->getId(), $reloaded->getAssignedRole()?->getId());
        self::assertSame($creator->getId(), $reloaded->getAssignedUser()?->getId());
    }

    public function testLeadershipCanAssignATaskToARole(): void
    {
        $unit = (new Unit())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $role = (new Role())->setCode('ccp')->setName('Coordinación pedagógica');
        $this->em->persist($role);
        // A director holds the 'direction' role (leadership) — not the admin flag, so this exercises
        // the role-based permission, not the ROLE_ADMIN shortcut.
        $direction = (new Role())->setCode('direction')->setName('Dirección');
        $this->em->persist($direction);
        $director = $this->user('director@centro.test', $unit);
        $director->addAssignedRole($direction);
        $task = new Task('Acta de reunión', '2025-2026', new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE);
        $task->setUnit($unit)->setAssignedUser($director)->setResponsibility(new PersonResponsibility($director))->setCreatedBy($director);
        $this->em->persist($task);
        $this->em->flush();
        $roleId = (int) $role->getId();

        $this->client->loginUser($director);
        $crawler = $this->client->request('GET', '/tareas/'.$task->getId().'/editar');
        // Leadership gets the responsibility-mode selector (a plain member does not).
        self::assertSelectorExists('[name="task_form[responsibilityMode]"]');
        $form = $crawler->selectButton('Guardar')->form();
        $form['task_form[responsibilityMode]'] = 'role';
        $form['task_form[responsibilityRole]'] = (string) $roleId;
        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->em->clear();
        $reloaded = $this->em->getRepository(Task::class)->find($task->getId());
        self::assertNotNull($reloaded);
        $responsibility = $reloaded->getResponsibility();
        self::assertInstanceOf(RoleResponsibility::class, $responsibility);
        self::assertSame($roleId, $responsibility->getRole()?->getId());
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
