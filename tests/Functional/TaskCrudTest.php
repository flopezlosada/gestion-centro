<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Department;
use App\Entity\NonLectiveDay;
use App\Entity\Role;
use App\Entity\Task;
use App\Entity\TaskComment;
use App\Entity\TaskResponsibility;
use App\Entity\User;
use App\Enum\DeliverableRequirement;
use App\Enum\TaskType;
use App\Repository\NotificationRepository;
use App\Service\FileUploader;
use App\Service\TaskReminderNotifier;
use App\Support\TaskStatus;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\Email;

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

    /**
     * Moves a task's lifecycle place BETWEEN requests, re-reading it through the entity manager of the
     * container that is alive right now. El browser reinicia el kernel en cada petición: el manager
     * capturado en setUp queda reseteado y las entidades que trajo, detached — un flush() sobre ellas no
     * escribe nada en silencio. Devuelve la tarea recargada para poder aseverar sobre ella.
     */
    private function moveTaskTo(int $taskId, string $status): Task
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $task = $em->getRepository(Task::class)->find($taskId);
        self::assertNotNull($task);
        $task->setStatus($status);
        $em->flush();

        return $task;
    }

    /**
     * Sends the CREATE form choosing the people it is for. The person step is a list of CHECKBOXES there
     * (one task per person), and DomCrawler only ever touches the first control of a group when you
     * assign to it — hence getPhpValues() plus a raw request, the idiom this project already uses for
     * expanded choices.
     *
     * @param \Symfony\Component\DomCrawler\Form $form    the create form, already filled
     * @param list<string>                        $userIds the ids to tick
     */
    private function submitCreateForm(\Symfony\Component\DomCrawler\Form $form, array $userIds): void
    {
        $values = $form->getPhpValues();
        $values['task_form']['responsibilityUsers'] = $userIds;
        $this->client->request($form->getMethod(), $form->getUri(), $values);
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
        $this->submitCreateForm($form, [(string) $creator->getId()]);

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
        // Crearte una tarea a ti mismo no dispara un correo de asignación.
        self::assertEmailCount(0);
    }

    public function testCreatingATaskForASubordinateEmailsThem(): void
    {
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        // A head of department commands their department, so they may create a task for a member of it.
        $headRole = (new Role())->setCode('head_dept')->setName('Jefatura de departamento')->setPerDepartment(true)->setHierarchyLevel(10);
        $this->em->persist($headRole);
        $teacherRole = (new Role())->setCode('teacher')->setName('Docente')->setPerDepartment(true);
        $this->em->persist($teacherRole);
        $boss = $this->user('jefa@centro.test', $unit);
        $boss->addAssignedRole($headRole);
        $member = $this->user('subordinado@centro.test', $unit);
        $member->addAssignedRole($teacherRole);
        $this->em->flush();
        $roleId = (int) $teacherRole->getId();
        $unitId = (int) $unit->getId();
        $memberId = (int) $member->getId();
        $this->client->loginUser($boss);

        $crawler = $this->client->request('GET', '/tareas/nueva');
        $form = $crawler->selectButton('Crear tarea')->form();
        $form['task_form[title]'] = 'Rellenar actas';
        $form['task_form[dueDate]'] = '2026-09-15';
        $form['task_form[responsibilityRole]'] = (string) $roleId;
        $form['task_form[responsibilityUnit]'] = (string) $unitId;
        $this->submitCreateForm($form, [(string) $memberId]);

        self::assertResponseRedirects();
        // El subordinado recibe un correo de la tarea que le han asignado.
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('subordinado@centro.test', $email->getTo()[0]->getAddress());
        self::assertStringContainsString('Rellenar actas', (string) $email->getSubject());
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
        $this->submitCreateForm($form, [(string) $member->getId()]);

        self::assertNull($this->em->getRepository(Task::class)->findOneBy(['title' => 'Tarea prohibida']));
    }

    public function testCancelIsNotOfferedToAPlainAssignee(): void
    {
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $member = $this->user('profe@centro.test', $unit);
        $other = $this->user('otro@centro.test', $unit);
        // Asignada al miembro pero creada por otra persona: no es creador ni superior → no puede gestionar.
        // Fecha EN PLAZO y relativa a hoy: si estuviera pasada, "Cancelar" desaparecería por estar fuera
        // de plazo y el test pasaría sin probar lo que dice su nombre.
        $task = new Task('Preparar el acta', '2025-2026', new \DateTimeImmutable('+30 days'), TaskType::SIMPLE);
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
        // En plazo: cancelar solo existe mientras la fecha no ha pasado (ver el test siguiente).
        $task = new Task('Actividad anulada', '2025-2026', new \DateTimeImmutable('+30 days'), TaskType::SIMPLE);
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

    /**
     * Una tarea FUERA DE PLAZO no se cancela ni siquiera desde arriba: petición del centro, para que
     * pasarse de fecha no sea una vía de escape. El botón no se ofrece Y el POST directo se rechaza —
     * si solo se escondiera el botón, esto no sería una regla.
     */
    public function testAnOverdueTaskCannotBeCancelled(): void
    {
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $headStudiesRole = (new Role())->setCode('head_of_studies')->setName('Jefatura de estudios')->setHierarchyLevel(30);
        $this->em->persist($headStudiesRole);
        $boss = $this->user('jefatura@centro.test', $unit);
        $boss->addAssignedRole($headStudiesRole);
        $member = $this->user('profe@centro.test', $unit);
        $task = new Task('Memoria sin entregar', '2025-2026', new \DateTimeImmutable('-3 days'), TaskType::SIMPLE);
        $task->setUnit($unit)->setAssignedUser($member)->setCreatedBy($boss);
        $this->em->persist($task);
        $this->em->flush();
        $taskId = $task->getId();

        $this->client->loginUser($boss);
        $crawler = $this->client->request('GET', '/tareas/'.$taskId);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[action$="/accion/cancel"]', 'fuera de plazo no se ofrece cancelar');
        // "Dar por finalizada" SÍ sigue estando: la salida de una tarea pasada de fecha existe, es otra.
        self::assertSelectorExists('form[action$="/accion/validate"]');

        // Y el POST a pelo tampoco pasa. El token se lee DE LA PÁGINA (todas las transiciones comparten
        // el mismo `task_transition<id>`): pedírselo al token manager devuelve uno de otra sesión.
        $token = $crawler->filter('form[action$="/accion/validate"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/tareas/'.$taskId.'/accion/cancel', ['_token' => $token]);
        self::assertResponseRedirects();
        $this->em->clear();
        self::assertSame('pending', $this->em->getRepository(Task::class)->find($taskId)?->getStatus());
    }

    public function testDeliverableCannotBeSubmittedWithoutReference(): void
    {
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $member = $this->user('profe@centro.test', $unit);
        $task = new Task('Memoria', '2025-2026', new \DateTimeImmutable('2026-06-30'), TaskType::WITH_DELIVERABLE);
        $task->setUnit($unit)->setAssignedUser($member)->setDeliverable(DeliverableRequirement::LINK);
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

    /**
     * El ciclo entero que describió el centro, de punta a punta: se entrega con un comentario, quien la
     * mandó la devuelve diciendo qué cambiar, se vuelve a entregar y se da por finalizada. Cada paso deja
     * su comentario en el hilo y avisa a la OTRA parte, nunca a quien acaba de actuar.
     */
    public function testTheDeliveryCycleGoesBackAndForthWithComments(): void
    {
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $headRole = (new Role())->setCode('head_dept')->setName('Jefatura de departamento')->setPerDepartment(true)->setHierarchyLevel(10);
        $this->em->persist($headRole);
        $boss = $this->user('jefa@centro.test', $unit);
        $boss->addAssignedRole($headRole);
        $member = $this->user('profe@centro.test', $unit);
        $task = new Task('Memoria', '2025-2026', new \DateTimeImmutable('+20 days'), TaskType::WITH_DELIVERABLE);
        $task->setUnit($unit)->setAssignedUser($member)->setCreatedBy($boss)->setDeliverable(DeliverableRequirement::LINK);
        $this->em->persist($task);
        $this->em->flush();
        $taskId = (int) $task->getId();

        // 1. El profesor entrega, con enlace y con una nota.
        $this->client->loginUser($member);
        $crawler = $this->client->request('GET', '/tareas/'.$taskId);
        $token = $crawler->filter('form[action$="/accion/submit"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/tareas/'.$taskId.'/accion/submit', [
            '_token' => $token,
            'reference' => 'https://cloud.educa.madrid.org/memoria',
            'comentario' => 'Va la parte de resultados; los anexos los mando aparte.',
        ]);
        self::assertResponseRedirects();
        self::assertSame('submitted', $this->reloadTask($taskId)->getStatus());

        // 2. La jefa la devuelve DICIENDO qué cambiar. Sin ese texto no se movería (se prueba abajo).
        $this->client->loginUser($boss);
        $crawler = $this->client->request('GET', '/tareas/'.$taskId);
        $token = $crawler->filter('form[action$="/accion/review"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/tareas/'.$taskId.'/accion/review', [
            '_token' => $token,
            'comentario' => 'Faltan los datos del tercer trimestre.',
        ]);
        self::assertResponseRedirects();
        self::assertSame('in_review', $this->reloadTask($taskId)->getStatus(), 'devuelta NO es volver a Pendiente');

        // 3. El profesor corrige y vuelve a entregar: no tiene que repetir el enlace, ya está puesto.
        $this->client->loginUser($member);
        $crawler = $this->client->request('GET', '/tareas/'.$taskId);
        $token = $crawler->filter('form[action$="/accion/submit"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/tareas/'.$taskId.'/accion/submit', ['_token' => $token, 'comentario' => 'Añadido el tercer trimestre.']);
        self::assertResponseRedirects();
        self::assertSame('submitted', $this->reloadTask($taskId)->getStatus());

        // 4. La jefa la da por finalizada.
        $this->client->loginUser($boss);
        $crawler = $this->client->request('GET', '/tareas/'.$taskId);
        $token = $crawler->filter('form[action$="/accion/validate"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/tareas/'.$taskId.'/accion/validate', ['_token' => $token, 'comentario' => 'Perfecto, gracias.']);
        self::assertResponseRedirects();
        self::assertSame('validated', $this->reloadTask($taskId)->getStatus());

        // El hilo guarda las cuatro intervenciones, en orden, con la transición de cada una.
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $thread = $em->getRepository(TaskComment::class)->findThreadFor($this->reloadTask($taskId));
        self::assertSame(
            ['submit', 'review', 'submit', 'validate'],
            array_map(static fn (TaskComment $c): ?string => $c->getTransition(), $thread),
        );
    }

    /**
     * Una tarea para un COLECTIVO son N tareas independientes, no una compartida: cada persona entrega la
     * suya, comenta la suya y recibe su veredicto, y una sola fila no puede estar en cuatro estados a la
     * vez. Es lo que pidió el centro ("a un solo usuario o a un colectivo").
     */
    public function testCreatingForSeveralPeopleMakesOneTaskEach(): void
    {
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $headRole = (new Role())->setCode('head_dept')->setName('Jefatura de departamento')->setPerDepartment(true)->setHierarchyLevel(10);
        $teacherRole = (new Role())->setCode('teacher')->setName('Docente')->setPerDepartment(true);
        $this->em->persist($headRole);
        $this->em->persist($teacherRole);
        $boss = $this->user('jefa@centro.test', $unit);
        $boss->addAssignedRole($headRole);
        $one = $this->user('uno@centro.test', $unit);
        $one->addAssignedRole($teacherRole);
        $two = $this->user('dos@centro.test', $unit);
        $two->addAssignedRole($teacherRole);
        $this->em->flush();
        $this->client->loginUser($boss);

        $crawler = $this->client->request('GET', '/tareas/nueva');
        $form = $crawler->selectButton('Crear tarea')->form();
        $form['task_form[title]'] = 'Rellenar la encuesta';
        $form['task_form[dueDate]'] = '2026-09-15';
        $form['task_form[responsibilityRole]'] = (string) $teacherRole->getId();
        $form['task_form[responsibilityUnit]'] = (string) $unit->getId();
        $this->submitCreateForm($form, [(string) $one->getId(), (string) $two->getId()]);

        // Con varias no hay una ficha a la que ir: se vuelve al listado.
        self::assertResponseRedirects('/tareas');
        $this->em->clear();
        $tasks = $this->em->getRepository(Task::class)->findBy(['title' => 'Rellenar la encuesta']);
        self::assertCount(2, $tasks, 'una tarea por persona');
        $assignees = array_map(static fn (Task $t): ?string => $t->getAssignedUser()?->getEmail(), $tasks);
        sort($assignees);
        self::assertSame(['dos@centro.test', 'uno@centro.test'], $assignees);
        // Y cada una avisa a la suya: dos correos, no uno.
        self::assertEmailCount(2);
    }

    /**
     * Sin departamento, al crear para varias personas, cada tarea cae en el departamento DE SU PERSONA.
     * Es lo que hace posible mandar algo a todo el claustro de una vez, sin repetir el formulario por
     * cada departamento del centro.
     */
    public function testWithNoDepartmentEachTaskLandsInItsOwnPersonsDepartment(): void
    {
        $maths = (new Department())->setCode('maths')->setName('Matemáticas');
        $language = (new Department())->setCode('lang')->setName('Lengua');
        $this->em->persist($maths);
        $this->em->persist($language);
        $directionRole = (new Role())->setCode('direction')->setName('Dirección')->setHierarchyLevel(50);
        $teacherRole = (new Role())->setCode('teacher')->setName('Docente')->setPerDepartment(true);
        $this->em->persist($directionRole);
        $this->em->persist($teacherRole);
        $director = $this->user('director@centro.test', $maths);
        $director->addAssignedRole($directionRole);
        $mathsTeacher = $this->user('mates@centro.test', $maths);
        $mathsTeacher->addAssignedRole($teacherRole);
        $langTeacher = $this->user('lengua@centro.test', $language);
        $langTeacher->addAssignedRole($teacherRole);
        $this->em->flush();
        $this->client->loginUser($director);

        $crawler = $this->client->request('GET', '/tareas/nueva');
        $form = $crawler->selectButton('Crear tarea')->form();
        $form['task_form[title]'] = 'Leer el plan de convivencia';
        $form['task_form[dueDate]'] = '2026-09-15';
        $form['task_form[responsibilityRole]'] = (string) $teacherRole->getId();
        // Departamento a propósito VACÍO: "todo el claustro".
        $this->submitCreateForm($form, [(string) $mathsTeacher->getId(), (string) $langTeacher->getId()]);

        self::assertResponseRedirects('/tareas');
        $this->em->clear();
        $tasks = $this->em->getRepository(Task::class)->findBy(['title' => 'Leer el plan de convivencia']);
        self::assertCount(2, $tasks);
        $byEmail = [];
        foreach ($tasks as $task) {
            $byEmail[(string) $task->getAssignedUser()?->getEmail()] = $task->getUnit()?->getName();
        }
        self::assertSame('Matemáticas', $byEmail['mates@centro.test']);
        self::assertSame('Lengua', $byEmail['lengua@centro.test']);
    }

    /**
     * La superficie nueva de entrega y comentarios se comprueba en el SERVIDOR, no escondiendo botones:
     * quien no tiene nada que ver con la tarea no comenta ni se descarga lo entregado, y una tarea cerrada
     * no admite más comentarios.
     */
    public function testCommentsAndTheDeliveredFileAreGuardedServerSide(): void
    {
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
        $other = (new Department())->setCode('lang')->setName('Lengua');
        $this->em->persist($unit);
        $this->em->persist($other);
        $member = $this->user('profe@centro.test', $unit);
        $outsider = $this->user('ajeno@centro.test', $other);
        $task = new Task('Memoria', '2025-2026', new \DateTimeImmutable('+20 days'), TaskType::WITH_DELIVERABLE);
        $task->setUnit($unit)->setAssignedUser($member)->setCreatedBy($member)->setDeliverable(DeliverableRequirement::ANY);
        $this->em->persist($task);
        $this->em->flush();
        $taskId = (int) $task->getId();

        // Quien tiene la tarea sí comenta.
        $this->client->loginUser($member);
        $crawler = $this->client->request('GET', '/tareas/'.$taskId);
        $token = $crawler->filter('form[action$="/comentario"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/tareas/'.$taskId.'/comentario', ['_token' => $token, 'comentario' => '¿Vale con el guion de la memoria?']);
        self::assertResponseRedirects();
        self::assertCount(1, self::getContainer()->get(EntityManagerInterface::class)->getRepository(TaskComment::class)->findBy(['task' => $taskId]));

        // Una persona de otro departamento no ve la tarea, así que ni comenta ni descarga.
        $this->client->loginUser($outsider);
        $this->client->request('POST', '/tareas/'.$taskId.'/comentario', ['_token' => $token, 'comentario' => 'me cuelo']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/tareas/'.$taskId.'/entregable/archivo');
        self::assertResponseStatusCodeSame(403);

        // Y sobre una tarea ya cerrada no se sigue comentando: su hilo es el registro de cómo se llegó ahí.
        $this->moveTaskTo($taskId, TaskStatus::VALIDATED);
        $this->client->loginUser($member);
        $this->client->request('POST', '/tareas/'.$taskId.'/comentario', ['_token' => $token, 'comentario' => 'una más']);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Una tarea que pide ARCHIVO se entrega subiéndolo, y lo que se sube pasa por la misma política de
     * documentos que el resto del centro: un tipo no admitido se rechaza sin mover la tarea.
     */
    public function testATaskThatAsksForAFileIsDeliveredByUploadingIt(): void
    {
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $member = $this->user('profe@centro.test', $unit);
        $task = new Task('Hoja firmada', '2025-2026', new \DateTimeImmutable('+20 days'), TaskType::WITH_DELIVERABLE);
        $task->setUnit($unit)->setAssignedUser($member)->setCreatedBy($member)->setDeliverable(DeliverableRequirement::FILE);
        $this->em->persist($task);
        $this->em->flush();
        $taskId = (int) $task->getId();

        $this->client->loginUser($member);
        $crawler = $this->client->request('GET', '/tareas/'.$taskId);
        $token = $crawler->filter('form[action$="/accion/submit"] input[name="_token"]')->attr('value');

        // Un ejecutable no entra: misma política que las fotocopias y el banco de tareas.
        $this->client->request('POST', '/tareas/'.$taskId.'/accion/submit', ['_token' => $token], ['archivo' => $this->upload('malo.exe')]);
        self::assertResponseRedirects();
        self::assertSame('pending', $this->reloadTask($taskId)->getStatus(), 'un tipo no admitido no entrega la tarea');

        // Un PDF sí, y queda descargable para quien la tarea concierne.
        $this->client->request('POST', '/tareas/'.$taskId.'/accion/submit', ['_token' => $token], ['archivo' => $this->upload('memoria.pdf')]);
        self::assertResponseRedirects();
        $stored = $this->reloadTask($taskId);
        self::assertSame('submitted', $stored->getStatus());
        self::assertSame('memoria.pdf', $stored->getDeliverableFileName());

        $this->client->request('GET', '/tareas/'.$taskId.'/entregable/archivo');
        self::assertResponseIsSuccessful();

        self::getContainer()->get(FileUploader::class)->remove((string) $stored->getDeliverableFilePath());
    }

    /**
     * A throwaway upload with the given name, written to the system temp dir (test mode, so the file is
     * moved rather than checked as a real HTTP upload).
     */
    private function upload(string $name): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.uniqid('entrega-', true).'-'.$name;
        file_put_contents($path, 'contenido de prueba');

        return new UploadedFile($path, $name, null, null, true);
    }

    /** Devolver sin decir qué cambiar deja a la otra persona adivinando: el servidor lo frena. */
    public function testATaskCannotBeSentBackWithoutSayingWhatToChange(): void
    {
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $headRole = (new Role())->setCode('head_dept')->setName('Jefatura de departamento')->setPerDepartment(true)->setHierarchyLevel(10);
        $this->em->persist($headRole);
        $boss = $this->user('jefa@centro.test', $unit);
        $boss->addAssignedRole($headRole);
        $member = $this->user('profe@centro.test', $unit);
        $task = new Task('Memoria', '2025-2026', new \DateTimeImmutable('+20 days'), TaskType::SIMPLE);
        $task->setUnit($unit)->setAssignedUser($member)->setCreatedBy($boss)->setStatus('submitted');
        $this->em->persist($task);
        $this->em->flush();
        $taskId = (int) $task->getId();

        $this->client->loginUser($boss);
        $crawler = $this->client->request('GET', '/tareas/'.$taskId);
        $token = $crawler->filter('form[action$="/accion/review"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/tareas/'.$taskId.'/accion/review', ['_token' => $token, 'comentario' => '']);

        self::assertResponseRedirects();
        self::assertSame('submitted', $this->reloadTask($taskId)->getStatus(), 'sin explicación no se devuelve');
    }

    /**
     * Re-reads a task through the container that is alive right now: the browser reboots the kernel on
     * every request, so the manager captured in setUp is reset and its entities detached.
     */
    private function reloadTask(int $id): Task
    {
        $task = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Task::class)->find($id);
        self::assertNotNull($task);

        return $task;
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
        // Aviso destacado arriba: sin él, el usuario no percibe que ha fallado (solo ve la cabecera).
        self::assertSelectorExists('[data-form-error]');
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
        // Editar sigue siendo de UNA persona: allí el paso sigue siendo un desplegable, no casillas.
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
        $this->submitCreateForm($form, [(string) $creatorId]);

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
        $this->submitCreateForm($form, [(string) $outsider->getId()]);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->em->getRepository(Task::class)->findOneBy(['title' => 'Tarea mal asignada']));
    }

    public function testSuperiorDoesNotSeeExecutionActionsOnASubordinatesTask(): void
    {
        // The head of studies holds a centre-wide ranked role, so they command every department and
        // outrank a maths teacher's task — pero su papel es supervisar, no ejecutar: no debe ver
        // Entregar, ni el entregable, ni marcar hecho, ni delegar la tarea de otro.
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
        $this->client->request('GET', '/tareas/'.$task->getId());
        self::assertResponseIsSuccessful();
        // Ejecutar es del asignado, no del superior por rango.
        self::assertSelectorNotExists('form[action$="/accion/submit"]', 'el superior no ejecuta (Entregar) la tarea del subordinado');
        self::assertSelectorNotExists('form[action$="/hecho"]', 'el superior no marca hecho por el subordinado');
        self::assertSelectorNotExists('form[action$="/entregable"]', 'el superior no adjunta el entregable del subordinado');
        // Delegar es del titular (jefe de departamento), no de un superior de rango superior.
        self::assertSelectorNotExists('select[name="delegatedTo"]', 'un superior de rango superior no delega la tarea de otro');
    }

    public function testSuperiorCanValidateASubordinatesSubmittedTask(): void
    {
        // La acción que SÍ le corresponde al superior: validar cuando la tarea está Entregada.
        $maths = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($maths);
        $headStudiesRole = (new Role())->setCode('head_of_studies')->setName('Jefatura de estudios')->setHierarchyLevel(30);
        $this->em->persist($headStudiesRole);
        $headStudies = $this->user('jefatura@centro.test', $maths);
        $headStudies->addAssignedRole($headStudiesRole);
        $teacher = $this->user('profe@centro.test', $maths);
        // Ya entregada por el docente, a la espera de validación del superior.
        $task = (new Task('Acta', '2025-2026', new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE))
            ->setUnit($maths)->setAssignedUser($teacher)->setCreatedBy($teacher)->setStatus('submitted');
        $this->em->persist($task);
        $this->em->flush();

        $this->client->loginUser($headStudies);
        $crawler = $this->client->request('GET', '/tareas/'.$task->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action$="/accion/validate"]', 'el superior sí valida una tarea entregada');
        $this->client->submit($crawler->filter('form[action$="/accion/validate"]')->form());

        self::assertResponseRedirects();
        $this->em->clear();
        $reloaded = $this->em->getRepository(Task::class)->find($task->getId());
        self::assertSame('validated', $reloaded?->getStatus());
    }

    public function testLateralUserIsNeitherSuperiorNorCanReachTheTask(): void
    {
        // Two departments: a teacher in one is neither owner nor superior of a task in the other, so a
        // lateral colleague must not reach it at all (visibility 403).
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

    /**
     * Delegating must not be a one-way trip: the titular keeps the control to retire or change their own
     * delegation. It used to vanish the moment they delegated ({@see Task::isOwnedBy()} handed ownership
     * to the delegatee in exclusive), leaving them unable to undo what they had just done — that is what
     * the directora hit with her three tasks.
     */
    public function testTitularCanRetireTheirOwnDelegation(): void
    {
        $dept = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($dept);
        $headRole = (new Role())->setCode('head_dept')->setName('Jefatura de departamento')->setPerDepartment(true)->setHierarchyLevel(10);
        $this->em->persist($headRole);
        $boss = $this->user('jefa@centro.test', $dept);
        $boss->addAssignedRole($headRole);
        $member = $this->user('profe@centro.test', $dept);
        $task = new Task('Memoria', '2025-2026', new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE);
        // Already delegated: the state the titular was locked out of.
        $task->setUnit($dept)->setResponsibility(new TaskResponsibility($headRole, $dept))
            ->setAssignedUser($boss)->setCreatedBy($boss)->setDelegatedTo($member);
        $this->em->persist($task);
        $this->em->flush();
        $taskId = (int) $task->getId();

        $this->client->loginUser($boss);
        $crawler = $this->client->request('GET', '/tareas/'.$taskId);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('select[name="delegatedTo"]', 'quien delegó sigue viendo el control de delegación');

        // "— Sin delegar —": the empty value recalls it.
        $form = $crawler->filter('form[action$="/delegar"]')->form();
        $form['delegatedTo'] = '';
        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->em->clear();
        self::assertNull(
            $this->em->getRepository(Task::class)->find($taskId)?->getDelegatedTo(),
            'la delegación se retira y la tarea vuelve a su responsable estructural',
        );
    }

    /**
     * A superior may close a Pendiente ("Dar por finalizada"): the work got done outside the app, or its
     * holder cannot deliver it. Before this the only exit was Cancelar — recorded as void, and terminal.
     */
    public function testSuperiorCanCloseAPendingTaskFromTheDetail(): void
    {
        $dept = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($dept);
        $teacherRole = (new Role())->setCode('teacher')->setName('Docente')->setPerDepartment(true);
        $headStudies = (new Role())->setCode('head_of_studies')->setName('Jefatura de estudios')->setHierarchyLevel(30);
        $this->em->persist($teacherRole);
        $this->em->persist($headStudies);
        $boss = $this->user('jefatura@centro.test', $dept);
        $boss->addAssignedRole($headStudies);
        $teacher = $this->user('profe@centro.test', $dept);
        $teacher->addAssignedRole($teacherRole);
        $task = new Task('Acta de la CCP', '2025-2026', new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE);
        $task->setUnit($dept)->setResponsibility(new TaskResponsibility($teacherRole, $dept))->setAssignedUser($teacher);
        $this->em->persist($task);
        $this->em->flush();
        $taskId = (int) $task->getId();

        $this->client->loginUser($boss);
        $crawler = $this->client->request('GET', '/tareas/'.$taskId);
        self::assertResponseIsSuccessful();
        // Sobre una Pendiente el mismo cierre se llama "Dar por finalizada": no hay nada entregado que
        // validar. Se mira en la tarjeta de decisión (`.decision`), que es donde vive la acción desde el
        // rediseño de la ficha; y los formularios se apuntan por su RUTA, que no cambia con la maqueta.
        self::assertSelectorTextContains('.decision', 'Dar por finalizada');
        self::assertSelectorNotExists('form[action$="/accion/submit"]', 'un superior no ejecuta la tarea de otro');

        $this->client->submit($crawler->filter('form[action$="/accion/validate"]')->form());

        self::assertResponseRedirects();
        $this->em->clear();
        $reloaded = $this->em->getRepository(Task::class)->find($taskId);
        self::assertSame(TaskStatus::VALIDATED, $reloaded?->getStatus());
        self::assertSame($teacher->getId(), $reloaded->getCompletedBy()?->getId(), 'quien la hizo sigue siendo el responsable');
    }

    /** The person who owes the work is never offered that shortcut: it would be self-validation. */
    public function testAssigneeIsNotOfferedToCloseTheirOwnPendingTask(): void
    {
        $dept = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($dept);
        $teacherRole = (new Role())->setCode('teacher')->setName('Docente')->setPerDepartment(true);
        $this->em->persist($teacherRole);
        $teacher = $this->user('profe@centro.test', $dept);
        $teacher->addAssignedRole($teacherRole);
        $task = new Task('Acta de la CCP', '2025-2026', new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE);
        $task->setUnit($dept)->setResponsibility(new TaskResponsibility($teacherRole, $dept))->setAssignedUser($teacher);
        $this->em->persist($task);
        $this->em->flush();

        $this->client->loginUser($teacher);
        $this->client->request('GET', '/tareas/'.$task->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action$="/accion/submit"]', 'el responsable entrega');
        self::assertSelectorNotExists('form[action$="/accion/validate"]', 'pero no se cierra su propia tarea');
    }

    /**
     * "Recordar" is a supervision control: the superior nudges whoever owes the work, at most once a day.
     * It exists because the automatic reminders only fire on exact days and stop reaching the person once
     * the task is overdue.
     */
    public function testSuperiorCanNudgeTheResponsibleOncePerDay(): void
    {
        $dept = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($dept);
        $teacherRole = (new Role())->setCode('teacher')->setName('Docente')->setPerDepartment(true);
        $headStudies = (new Role())->setCode('head_of_studies')->setName('Jefatura de estudios')->setHierarchyLevel(30);
        $this->em->persist($teacherRole);
        $this->em->persist($headStudies);
        $boss = $this->user('jefatura@centro.test', $dept);
        $boss->addAssignedRole($headStudies);
        $teacher = $this->user('profe@centro.test', $dept);
        $teacher->addAssignedRole($teacherRole);
        $task = new Task('Acta de la CCP', '2025-2026', new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE);
        $task->setUnit($dept)->setResponsibility(new TaskResponsibility($teacherRole, $dept))->setAssignedUser($teacher);
        $this->em->persist($task);
        $this->em->flush();
        $taskId = (int) $task->getId();

        $this->client->loginUser($boss);
        $crawler = $this->client->request('GET', '/tareas/'.$taskId);
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form[action$="/recordar"]')->form());

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Recordatorio enviado');
        // El aviso in-app queda escrito para quien debe hacerla.
        $notices = self::getContainer()->get(NotificationRepository::class)->findRecentFor($teacher);
        self::assertCount(1, $notices);
        self::assertSame(TaskReminderNotifier::REMINDER_KIND, $notices[0]->getKind());

        // Segundo intento el mismo día: la ficha ya no ofrece el botón, y el endpoint tampoco duplica.
        $crawler = $this->client->request('GET', '/tareas/'.$taskId);
        self::assertSelectorTextContains('body', 'Avisado hoy');
        self::assertCount(0, $crawler->filter('form[action$="/recordar"]'), 'sin botón: ya se avisó hoy');
        self::assertCount(1, self::getContainer()->get(NotificationRepository::class)->findRecentFor($teacher), 'un solo aviso');
    }

    /** Nudging is for whoever does NOT owe the work: the responsible never gets the button. */
    public function testAssigneeIsNotOfferedTheNudgeButton(): void
    {
        $dept = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($dept);
        $teacherRole = (new Role())->setCode('teacher')->setName('Docente')->setPerDepartment(true);
        $this->em->persist($teacherRole);
        $teacher = $this->user('profe@centro.test', $dept);
        $teacher->addAssignedRole($teacherRole);
        $task = new Task('Acta de la CCP', '2025-2026', new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE);
        $task->setUnit($dept)->setResponsibility(new TaskResponsibility($teacherRole, $dept))->setAssignedUser($teacher);
        $this->em->persist($task);
        $this->em->flush();

        $this->client->loginUser($teacher);
        $crawler = $this->client->request('GET', '/tareas/'.$task->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form[action$="/recordar"]'), 'no te recuerdas a ti mismo');
    }

    public function testEmptyNewTaskFormReportsTheBlankFieldsInsteadOfCrashing(): void
    {
        // Guardar el formulario vacío devolvía un 500: el título vacío llegaba como null a
        // TaskFormData::$title (string no nulable) al mapear, antes de que el validador pudiera hablar.
        // Con el campo obligatorio produciendo '' (RequiredTextEmptyDataExtension), el submit vacío es
        // un 422 con los errores de campo y el banner de la macro f.submit_error.
        $unit = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($unit);
        $user = $this->user('jefa@centro.test', $unit);
        $this->em->flush();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/tareas/nueva');
        // El navegador ya no bloquea el envío (los form llevan novalidate para poder mostrar el aviso),
        // así que el submit llega al servidor con todo en blanco.
        $form = $crawler->selectButton('Crear tarea')->form();
        $form['task_form[title]'] = '';
        $form['task_form[dueDate]'] = '';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('[data-form-error]');
        self::assertSelectorTextContains('body', 'El título es obligatorio.');
        self::assertSelectorTextContains('body', 'Pon una fecha límite.');
        self::assertSame(0, $this->em->getRepository(Task::class)->count([]), 'nothing was persisted');
    }

    public function testDelegationIsNotOfferedOnceTheTaskIsSubmitted(): void
    {
        // Delegar cambia el titular: solo cabe mientras la tarea sigue Pendiente. Una entregada (o
        // finalizada) ya no se reasigna, así que el control desaparece y el POST se rechaza.
        $dept = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($dept);
        $headRole = (new Role())->setCode('head_dept')->setName('Jefatura de departamento')->setPerDepartment(true)->setHierarchyLevel(10);
        $this->em->persist($headRole);
        $boss = $this->user('jefa@centro.test', $dept);
        $boss->addAssignedRole($headRole);
        $member = $this->user('profe@centro.test', $dept);
        $task = new Task('Memoria', '2025-2026', new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE);
        $task->setUnit($dept)->setResponsibility(new TaskResponsibility($headRole, $dept))->setAssignedUser($boss)->setCreatedBy($boss);
        $this->em->persist($task);
        $this->em->flush();
        $memberId = (int) $member->getId();
        $taskId = (int) $task->getId();

        $this->client->loginUser($boss);
        // Con la tarea aún Pendiente el control está: de ahí sale un token CSRF válido para el endpoint
        // (la intención no depende del estado), que luego reusamos con la tarea ya Entregada.
        $crawler = $this->client->request('GET', '/tareas/'.$taskId);
        self::assertSelectorExists('select[name="delegatedTo"]');
        $token = (string) $crawler->filter('form[action$="/delegar"] input[name="_token"]')->attr('value');

        $this->moveTaskTo($taskId, TaskStatus::SUBMITTED);

        $this->client->request('GET', '/tareas/'.$taskId);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('select[name="delegatedTo"]', 'una tarea entregada ya no se delega');

        // Y por si alguien llega al endpoint a mano con un token válido: el servidor lo rechaza igual.
        $this->client->request('POST', '/tareas/'.$taskId.'/delegar', [
            '_token' => $token,
            'delegatedTo' => (string) $memberId,
        ]);

        self::assertResponseStatusCodeSame(403);

        // El mismo POST con la tarea de vuelta en Pendiente sí pasa: confirma que el 403 anterior venía
        // del estado de la tarea y no de un token CSRF caducado.
        $this->moveTaskTo($taskId, TaskStatus::PENDING);
        $this->client->request('POST', '/tareas/'.$taskId.'/delegar', [
            '_token' => $token,
            'delegatedTo' => (string) $memberId,
        ]);

        self::assertResponseRedirects();
        $reloaded = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Task::class)->find($taskId);
        self::assertSame($memberId, $reloaded?->getDelegatedTo()?->getId());
    }

    public function testFinishedTaskDoesNotOfferEditingTheDeliverable(): void
    {
        // El enlace del entregable solo se corrige mientras está Entregada; en una Finalizada el
        // formulario salía igualmente (y el POST lo rechazaba): la vista contradecía al servidor.
        $dept = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($dept);
        $role = (new Role())->setCode('direction')->setName('Dirección');
        $this->em->persist($role);
        $owner = $this->user('directora@centro.test', $dept);
        $owner->addAssignedRole($role);
        $task = new Task('Criterios de horarios', '2025-2026', new \DateTimeImmutable('2026-06-30'), TaskType::WITH_DELIVERABLE);
        $task->setResponsibility(new TaskResponsibility($role, null))->setAssignedUser($owner)->setCreatedBy($owner);
        // El tipo NO implica el flag: lo pone el formulario (applyFormData), así que aquí también.
        $task->setDeliverable(DeliverableRequirement::LINK);
        $task->setDeliverableReference('https://cloud.educa.madrid.org/A1-01');
        $task->setStatus(TaskStatus::VALIDATED);
        $this->em->persist($task);
        $this->em->flush();

        $this->client->loginUser($owner);
        $this->client->request('GET', '/tareas/'.$task->getId());

        self::assertResponseIsSuccessful();
        // El documento entregado sigue visible (solo lectura), pero sin formulario para cambiarlo.
        self::assertSelectorTextContains('body', 'Lo entregado');
        self::assertSelectorNotExists('form.deliverable-form');
        self::assertSelectorNotExists('select[name="delegatedTo"]');
    }

    public function testClosedTaskIsShownDoneAndCannotBeTicked(): void
    {
        // Una finalizada sigue apareciendo en su día del calendario: debe VERSE hecha (antes se pintaba
        // con el círculo vacío, porque la casilla miraba solo checkboxDone) y su casilla ya no acciona.
        $dept = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($dept);
        $owner = $this->user('profe@centro.test', $dept);
        // Mismo anclaje que el calendario (CalendarController::TIME_ZONE): hoy en Madrid, no en la zona
        // del runner de CI, para que el curso y el día de la vista coincidan con lo que calcula la app.
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Madrid'));
        $dueDate = $today->modify('-1 day');
        $task = new Task('Memoria', SchoolYear::current($today), $dueDate, TaskType::SIMPLE);
        $task->setAssignedUser($owner)->setCreatedBy($owner);
        $this->em->persist($task);
        $this->em->flush();
        $taskId = (int) $task->getId();
        // La vista de día del calendario: la lista de agenda que pinta el macro `agenda_item` desde que
        // se retiró /agenda. Se ancla en la fecha límite de la tarea, así que no depende del reloj.
        $dayView = '/calendario?vista=dia&fecha='.$dueDate->format('Y-m-d');

        $this->client->loginUser($owner);
        // Pendiente: la casilla acciona (POST) y de ella sale el token para el intento posterior.
        $crawler = $this->client->request('GET', $dayView);
        self::assertSelectorExists('form[action$="/tareas/'.$taskId.'/hecho"]');
        $token = (string) $crawler->filter('form[action$="/tareas/'.$taskId.'/hecho"] input[name="_token"]')->attr('value');

        $this->moveTaskTo($taskId, TaskStatus::VALIDATED);

        $this->client->request('GET', $dayView);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[action$="/tareas/'.$taskId.'/hecho"]', 'una tarea cerrada no acciona');
        self::assertSelectorExists('#tarea-'.$taskId.'.is-done');
        self::assertSelectorExists('#tarea-'.$taskId.' .checkbox.is-checked');

        $this->client->request('POST', '/tareas/'.$taskId.'/hecho', ['_token' => $token]);

        self::assertResponseStatusCodeSame(403);

        // Con la tarea reabierta el mismo POST pasa: el 403 era por estar cerrada, no por el token.
        $this->moveTaskTo($taskId, TaskStatus::PENDING);
        $this->client->request('POST', '/tareas/'.$taskId.'/hecho', ['_token' => $token]);

        self::assertResponseRedirects();
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

    /**
     * Una tarea de la CIMA de la jerarquía se cierra al entregarla, porque no hay superior que la valide.
     * Antes se quedaba en Entregada esperando a alguien que no existe: en la revisión del 31/07 había cuatro
     * tareas de Dirección así, y el único que podía cerrarlas era el TIC por ser superusuario técnico.
     */
    public function testATaskNobodyOutranksIsClosedOnDelivery(): void
    {
        $direction = (new Role())->setCode('direction')->setName('Dirección')->setHierarchyLevel(40);
        $this->em->persist($direction);
        $head = $this->user('directora@centro.test');
        $head->addAssignedRole($direction);
        $task = new Task('Memoria de dirección', '2025-2026', new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE);
        $task->setResponsibility(new TaskResponsibility($direction, null))->setAssignedUser($head);
        $this->em->persist($task);
        $this->em->flush();
        $taskId = (int) $task->getId();

        $this->client->loginUser($head);
        $crawler = $this->client->request('GET', '/tareas/'.$taskId);
        $this->client->submit($crawler->filter('form.actionbar__form--submit')->form());

        $this->em->clear();
        $reloaded = $this->em->getRepository(Task::class)->find($taskId);
        self::assertSame(TaskStatus::VALIDATED, $reloaded?->getStatus(), 'sin superior posible, entregar ES cerrar');
        self::assertSame($head->getId(), $reloaded->getCompletedBy()?->getId());
    }

    /**
     * Y esa misma persona puede REABRIRLA (decisión de Paco, 31/07): sin esa salida, un cierre por error
     * obligaba a crear una tarea nueva y se perdía el hilo de la original. Vuelve a Pendiente, no a
     * Entregada: si se reabre es porque hay algo que volver a hacer.
     */
    public function testTheTopOfTheChartCanReopenItsOwnFinishedTask(): void
    {
        $direction = (new Role())->setCode('direction')->setName('Dirección')->setHierarchyLevel(40);
        $this->em->persist($direction);
        $head = $this->user('directora@centro.test');
        $head->addAssignedRole($direction);
        $task = new Task('Memoria de dirección', '2025-2026', new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE);
        $task->setResponsibility(new TaskResponsibility($direction, null))->setAssignedUser($head);
        $task->setStatus(TaskStatus::VALIDATED);
        $this->em->persist($task);
        $this->em->flush();
        $taskId = (int) $task->getId();

        $this->client->loginUser($head);
        $crawler = $this->client->request('GET', '/tareas/'.$taskId);
        self::assertCount(1, $crawler->filter('form.actionbar__form--reopen'), 'una finalizada se puede reabrir');
        $this->client->submit($crawler->filter('form.actionbar__form--reopen')->form());

        $this->em->clear();
        self::assertSame(TaskStatus::PENDING, $this->em->getRepository(Task::class)->find($taskId)?->getStatus());
    }

    /** Y el responsable raso NO puede reabrir la suya: eso sigue siendo del superior. */
    public function testAPlainAssigneeCannotReopenTheirOwnFinishedTask(): void
    {
        $dept = (new Department())->setCode('maths')->setName('Matemáticas');
        $this->em->persist($dept);
        $teacherRole = (new Role())->setCode('teacher')->setName('Docente')->setPerDepartment(true);
        $head = (new Role())->setCode('head_dept')->setName('Jefatura de departamento')->setPerDepartment(true)->setHierarchyLevel(10);
        $this->em->persist($teacherRole);
        $this->em->persist($head);
        // Hay jefatura de departamento en el mismo departamento: alguien POR ENCIMA de la tarea existe.
        $boss = $this->user('jefa@centro.test', $dept);
        $boss->addAssignedRole($head);
        $teacher = $this->user('profe@centro.test', $dept);
        $teacher->addAssignedRole($teacherRole);
        $task = new Task('Programación', '2025-2026', new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE);
        $task->setUnit($dept)->setResponsibility(new TaskResponsibility($teacherRole, $dept))->setAssignedUser($teacher);
        $task->setStatus(TaskStatus::VALIDATED);
        $this->em->persist($task);
        $this->em->flush();

        $this->client->loginUser($teacher);
        $crawler = $this->client->request('GET', '/tareas/'.$task->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form.actionbar__form--reopen'), 'reabrir no es del responsable');
    }
}
