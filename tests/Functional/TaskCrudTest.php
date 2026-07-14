<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\NonLectiveDay;
use App\Entity\Role;
use App\Entity\Task;
use App\Entity\TaskResponsibility;
use App\Entity\Department;
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

    private function user(string $email, ?Department $unit = null): User
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
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $user = $this->user('jefa@centro.test', $unit);
        $this->em->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/tareas/nueva');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    public function testNewTaskPrefillsDueDateFromQuery(): void
    {
        // Arriving from the calendar's "+ Nueva tarea" carries the clicked day as ?fecha=; the
        // deadline field must render already filled with it.
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $user = $this->user('jefa@centro.test', $unit);
        $this->em->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/tareas/nueva?fecha=2026-09-15');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[name="task_form[dueDate]"][value="2026-09-15"]');
    }

    public function testNewTaskIgnoresAnInvalidFechaQuery(): void
    {
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $user = $this->user('jefa@centro.test', $unit);
        $this->em->flush();
        $this->client->loginUser($user);

        // A non-date value must not blow up: the form simply renders with an empty deadline.
        $this->client->request('GET', '/tareas/nueva?fecha=no-es-fecha');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[name="task_form[dueDate]"]');
    }

    public function testCreateTaskRecordsCreatorAndResponsibility(): void
    {
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
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
        $form['task_form[responsibilityUser]'] = (string) $creator->getId();
        $this->client->submit($form);

        self::assertResponseRedirects();
        $creatorId = $creator->getId();
        $this->em->clear();
        $task = $this->em->getRepository(Task::class)->findOneBy(['title' => 'Preparar la evaluación']);
        self::assertNotNull($task);
        self::assertSame($creatorId, $task->getCreatedBy()?->getId());
        self::assertNotNull($task->getResponsibility());
        self::assertSame($roleId, $task->getResponsibility()->getRole()->getId());
        self::assertSame($unitId, $task->getResponsibility()->getUnit()?->getId());
        // The chosen person is stored as the assignee.
        self::assertSame($creatorId, $task->getAssignedUser()?->getId());
    }

    public function testMemberOnlySeesRolesTheyCommandInNewTaskForm(): void
    {
        // A plain teacher creating a task may only target their own function: the "Rol responsable"
        // list shows Docente (their own role) but not Dirección (a role they neither hold nor command).
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $teacherRole = (new Role())->setCode('teacher')->setName('Docente')->setPerDepartment(true);
        $direction = (new Role())->setCode('direction')->setName('Dirección')->setHierarchyLevel(40);
        $this->em->persist($teacherRole);
        $this->em->persist($direction);
        $member = $this->user('profe@centro.test', $unit);
        $member->addAssignedRole($teacherRole);
        $this->em->flush();
        $this->client->loginUser($member);

        $crawler = $this->client->request('GET', '/tareas/nueva');

        self::assertResponseIsSuccessful();
        $roles = $crawler->filter('[name="task_form[responsibilityRole]"] option')->each(static fn ($node): string => $node->text());
        self::assertContains('Docente', $roles);
        self::assertNotContains('Dirección', $roles);
    }

    public function testMemberCannotCreateTaskForARoleTheyDoNotCommand(): void
    {
        // Server-side guard: even if the role is forced past the (filtered) dropdown, a plain teacher
        // cannot assign a task to Dirección — no task is created.
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $teacherRole = (new Role())->setCode('teacher')->setName('Docente')->setPerDepartment(true);
        $direction = (new Role())->setCode('direction')->setName('Dirección')->setHierarchyLevel(40);
        $this->em->persist($teacherRole);
        $this->em->persist($direction);
        $member = $this->user('profe@centro.test', $unit);
        $member->addAssignedRole($teacherRole);
        $this->em->flush();
        $this->client->loginUser($member);

        $crawler = $this->client->request('GET', '/tareas/nueva');
        $form = $crawler->selectButton('Crear tarea')->form();
        // Bypass the DomCrawler's own choice validation to force a role outside the allowed list.
        $form->disableValidation();
        $form['task_form[title]'] = 'Tarea prohibida';
        $form['task_form[dueDate]'] = '2026-09-15';
        $form['task_form[responsibilityRole]'] = (string) $direction->getId();
        $form['task_form[responsibilityUnit]'] = (string) $unit->getId();
        $form['task_form[responsibilityUser]'] = (string) $member->getId();
        $this->client->submit($form);

        self::assertNull($this->em->getRepository(Task::class)->findOneBy(['title' => 'Tarea prohibida']));
    }

    public function testCancelIsNotOfferedToAPlainAssignee(): void
    {
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $member = $this->user('profe@centro.test', $unit);
        $other = $this->user('otro@centro.test', $unit);
        // Asignada al miembro pero creada por otra persona: no es creador ni superior → no puede gestionar.
        $task = new Task('Preparar el acta', '2025-2026', new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE);
        $task->setUnit($unit)->setAssignedUser($member)->setCreatedBy($other);
        $this->em->persist($task);
        $this->em->flush();

        $this->client->loginUser($member);
        $this->client->request('GET', '/tareas/'.$task->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[action$="/accion/cancel"]', 'un asignado normal no puede cancelar');
    }

    public function testSuperiorCanCancelATask(): void
    {
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $headStudiesRole = (new Role())->setCode('head_of_studies')->setName('Jefatura de estudios')->setHierarchyLevel(30);
        $this->em->persist($headStudiesRole);
        // Un superior (jefatura de estudios) ve la tarea y puede gestionarla → cancelar.
        $boss = $this->user('jefatura@centro.test', $unit);
        $boss->addAssignedRole($headStudiesRole);
        $member = $this->user('profe@centro.test', $unit);
        $task = new Task('Actividad anulada', '2025-2026', new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE);
        $task->setUnit($unit)->setAssignedUser($member)->setCreatedBy($boss);
        $this->em->persist($task);
        $this->em->flush();
        $taskId = $task->getId();

        $this->client->loginUser($boss);
        $crawler = $this->client->request('GET', '/tareas/'.$taskId);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action$="/accion/cancel"]', 'un superior puede cancelar');
        $this->client->submit($crawler->filter('form[action$="/accion/cancel"]')->form());

        self::assertResponseRedirects();
        $this->em->clear();
        self::assertSame('cancelled', $this->em->getRepository(Task::class)->find($taskId)?->getStatus());
    }

    public function testDeliverableCannotBeSubmittedWithoutReference(): void
    {
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $member = $this->user('profe@centro.test', $unit);
        $task = new Task('Memoria', '2025-2026', new \DateTimeImmutable('2026-06-30'), TaskType::WITH_DELIVERABLE);
        $task->setUnit($unit)->setAssignedUser($member)->setRequiresDocument(true);
        $this->em->persist($task);
        $this->em->flush();
        $taskId = $task->getId();

        $this->client->loginUser($member);
        $crawler = $this->client->request('GET', '/tareas/'.$taskId);
        // Entregar sin rellenar el enlace (salta la validación de navegador): el estado no debe cambiar.
        $form = $crawler->filter('form[action$="/accion/submit"]')->form();
        $form->disableValidation();
        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->em->clear();
        self::assertSame('pending', $this->em->getRepository(Task::class)->find($taskId)?->getStatus(), 'sin enlace no se entrega');
    }

    public function testCannotCreateTaskDueOnAWeekend(): void
    {
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
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
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
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
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $direction = (new Role())->setCode('direction')->setName('Dirección');
        $ccp = (new Role())->setCode('ccp')->setName('Coordinación pedagógica');
        $this->em->persist($direction);
        $this->em->persist($ccp);
        $creator = $this->user('director@centro.test', $unit);
        // The creator holds both centre-wide roles, so they are a valid responsible person for either.
        $creator->addAssignedRole($direction);
        $creator->addAssignedRole($ccp);
        // A centre-wide responsibility to start with (no department), assigned to the creator.
        $task = new Task('Acta de reunión', '2025-2026', new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE);
        $task->setResponsibility(new TaskResponsibility($direction, null))->setAssignedUser($creator)->setCreatedBy($creator);
        $this->em->persist($task);
        $this->em->flush();
        $ccpId = (int) $ccp->getId();

        $this->client->loginUser($creator);
        $crawler = $this->client->request('GET', '/tareas/'.$task->getId().'/editar');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[name="task_form[responsibilityRole]"]');
        $form = $crawler->selectButton('Guardar')->form();
        $form['task_form[responsibilityRole]'] = (string) $ccpId;
        $form['task_form[responsibilityUser]'] = (string) $creator->getId();
        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->em->clear();
        $reloaded = $this->em->getRepository(Task::class)->find($task->getId());
        self::assertNotNull($reloaded);
        self::assertSame($ccpId, $reloaded->getResponsibility()?->getRole()->getId());
    }

    public function testTaskAssignsTheChosenPersonAmongSeveralRoleHolders(): void
    {
        $dept = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($dept);
        $teacherRole = (new Role())->setCode('teacher')->setName('Docente')->setPerDepartment(true);
        $this->em->persist($teacherRole);
        // Two teachers hold the role in the department; the creator must pick one, not get both.
        $creator = $this->user('ana@centro.test', $dept);
        $creator->addAssignedRole($teacherRole);
        $other = $this->user('otro@centro.test', $dept);
        $other->addAssignedRole($teacherRole);
        $this->em->flush();
        $creatorId = $creator->getId();
        $this->client->loginUser($creator);

        $crawler = $this->client->request('GET', '/tareas/nueva');
        $form = $crawler->selectButton('Crear tarea')->form();
        $form['task_form[title]'] = 'Acta del docente';
        $form['task_form[dueDate]'] = '2026-09-15';
        $form['task_form[responsibilityRole]'] = (string) $teacherRole->getId();
        $form['task_form[responsibilityUnit]'] = (string) $dept->getId();
        $form['task_form[responsibilityUser]'] = (string) $creatorId;
        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->em->clear();
        $task = $this->em->getRepository(Task::class)->findOneBy(['title' => 'Acta del docente']);
        self::assertNotNull($task);
        // Assigned to the chosen teacher only, even though the role resolves to several holders.
        self::assertSame($creatorId, $task->getAssignedUser()?->getId());
    }

    public function testCannotAssignTaskToAPersonWhoDoesNotHoldTheRole(): void
    {
        $dept = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($dept);
        $teacherRole = (new Role())->setCode('teacher')->setName('Docente')->setPerDepartment(true);
        $headRole = (new Role())->setCode('head_dept')->setName('Jefatura de departamento')->setPerDepartment(true)->setHierarchyLevel(10);
        $this->em->persist($teacherRole);
        $this->em->persist($headRole);
        // The creator is head of the department (ranked), so they command it and the outsider is within
        // their assignable scope; the outsider is a member of the same department but holds no role.
        $creator = $this->user('ana@centro.test', $dept);
        $creator->addAssignedRole($headRole);
        $outsider = $this->user('sinrol@centro.test', $dept);
        $this->em->flush();
        $this->client->loginUser($creator);

        $crawler = $this->client->request('GET', '/tareas/nueva');
        $form = $crawler->selectButton('Crear tarea')->form();
        $form['task_form[title]'] = 'Tarea mal asignada';
        $form['task_form[dueDate]'] = '2026-09-15';
        $form['task_form[responsibilityRole]'] = (string) $teacherRole->getId();
        $form['task_form[responsibilityUnit]'] = (string) $dept->getId();
        $form['task_form[responsibilityUser]'] = (string) $outsider->getId();
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->em->getRepository(Task::class)->findOneBy(['title' => 'Tarea mal asignada']));
    }

    public function testSuperiorCanWorkOnASubordinatesTask(): void
    {
        // The head of studies holds a centre-wide ranked role, so they command every department and
        // outrank a maths teacher's task.
        $maths = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($maths);
        $headStudiesRole = (new Role())->setCode('head_of_studies')->setName('Jefatura de estudios')->setHierarchyLevel(30);
        $this->em->persist($headStudiesRole);
        $headStudies = $this->user('jefatura@centro.test', $maths);
        $headStudies->addAssignedRole($headStudiesRole);
        $teacher = $this->user('profe@centro.test', $maths);
        // A task owned by the teacher — the head of studies is neither its assignee nor a role holder.
        $task = new Task('Acta', '2025-2026', new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE);
        $task->setUnit($maths)->setAssignedUser($teacher)->setCreatedBy($teacher);
        $this->em->persist($task);
        $this->em->flush();

        $this->client->loginUser($headStudies);
        $crawler = $this->client->request('GET', '/tareas/'.$task->getId());
        self::assertResponseIsSuccessful();
        // The progress action (Entregar) is offered to the superior, proving they may work on it.
        self::assertSelectorExists('form[action$="/accion/submit"]');
        $this->client->submit($crawler->filter('form[action$="/accion/submit"]')->form());

        self::assertResponseRedirects();
        $this->em->clear();
        $reloaded = $this->em->getRepository(Task::class)->find($task->getId());
        self::assertSame('submitted', $reloaded?->getStatus());
    }

    public function testLateralUserIsNeitherSuperiorNorCanReachTheTask(): void
    {
        // Two departments: a teacher in one is not a superior of a task in the other, so widening
        // canWorkOn to superiors must not leak a lateral colleague's task to them.
        $maths = (new Department())->setCode('maths')->setName('Matemáticas');
        $language = (new Department())->setCode('language')->setName('Lengua');
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
        // A head of department (per-department ranked role) commands their own department, so they may
        // delegate their own jefatura task to a member of it.
        $dept = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($dept);
        $headRole = (new Role())->setCode('head_dept')->setName('Jefatura de departamento')->setPerDepartment(true)->setHierarchyLevel(10);
        $this->em->persist($headRole);
        $boss = $this->user('jefa@centro.test', $dept);
        $boss->addAssignedRole($headRole);
        $member = $this->user('profe@centro.test', $dept);
        // A department task ("jefatura de departamento de Matemáticas"), which the head delegates to a member.
        $task = new Task('Memoria', '2025-2026', new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE);
        $task->setUnit($dept)->setResponsibility(new TaskResponsibility($headRole, $dept))->setAssignedUser($boss)->setCreatedBy($boss);
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
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
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
