<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Department;
use App\Entity\Role;
use App\Entity\Task;
use App\Entity\TaskComment;
use App\Entity\TaskResponsibility;
use App\Entity\User;
use App\Enum\DeliverableRequirement;
use App\Enum\TaskType;
use App\Form\TaskFormData;
use App\Form\TaskFormType;
use App\Repository\AuditLogRepository;
use App\Repository\RoleRepository;
use App\Repository\TaskCommentRepository;
use App\Repository\TaskRepository;
use App\Repository\UserRepository;
use App\Service\FileUploader;
use App\Service\OrganizationHierarchy;
use App\Service\TaskAssignmentNotifier;
use App\Service\TaskProgressNotifier;
use App\Service\TaskReminderNotifier;
use App\Service\TaskVisibility;
use App\Service\TaskWorkflow;
use App\Support\DocumentUpload;
use App\Support\TaskActivityPresenter;
use App\Support\TaskDetailView;
use App\Support\TaskStatus;
use App\Support\TickOutcome;
use App\Util\CalendarDate;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Read views for tasks: the course plan (list) and a task detail with its activity timeline.
 */
final class TaskController extends AbstractController
{
    /** Transitions reserved to the superior/admin (guarded by the workflow); the rest are progress. */
    private const array SUPERIOR_TRANSITIONS = ['validate', 'review', 'reopen'];

    /** The deadline windows of the narrowing form, aligned with the buckets Inicio already speaks. */
    private const array DATE_WINDOWS = [
        '7dias' => 'Próximos 7 días',
        'adelante' => 'Más adelante',
    ];

    /** The "persona" value meaning "nobody is on the hook for it": tasks with no resolvable responsible. */
    private const string NOBODY = 'nadie';

    /** Private-storage subdirectory for the files handed in with a task. */
    private const string DELIVERABLE_SUBDIR = 'task-deliverables';

    /** Ceiling for a comment, matching the column's validation on {@see TaskComment}. */
    private const int COMMENT_MAX = 2000;

    /**
     * The course plan, scoped to the tasks the user may see: their own, those under a unit they are a
     * superior of, or every task for an admin (see {@see TaskVisibility}). The page itself is open to
     * any authenticated user; the organisation chart decides what shows up, not the permission matrix.
     *
     * The screen separates two things that used to be tangled: NAVIGATING (the named views, which are
     * the questions people actually arrive with) and NARROWING (one form: departamento, persona, rol,
     * fecha, búsqueda). Every count is computed over the narrowed set, so a view always delivers the
     * number it promises — the old status chips counted the whole course and lied as soon as anything
     * was filtered. For the same reason "Esperando mi validación" asks the workflow whether the verdict
     * transition is actually enabled, instead of re-deriving the rule: the list must never offer what the
     * task detail will refuse.
     */
    #[Route('/tareas', name: 'task_index', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] User $user, TaskRepository $tasks, TaskVisibility $visibility, OrganizationHierarchy $hierarchy, TaskWorkflow $workflows): Response
    {
        $today = new \DateTimeImmutable('today');
        $todayStr = $today->format('Y-m-d');
        $isAdmin = $this->isGranted('ROLE_ADMIN');

        // Cursos entre los que se puede navegar (histórico): los que tienen tareas + el actual, por si
        // aún no tiene ninguna. El `curso` pedido se valida por FORMATO, no por pertenencia: antes se
        // aceptaba cualquier cadena y se reflejaba como pestaña activa y en el <title> con la pantalla a
        // cero; pero exigir que tenga tareas dejaría fuera un curso recién generado y aún vacío, al que
        // AdminAcademicYearController::generate() redirige.
        $years = $tasks->schoolYearsWithTasks();
        $currentYear = SchoolYear::current($today);
        if (!\in_array($currentYear, $years, true)) {
            $years[] = $currentYear;
        }
        $schoolYear = $request->query->getString('curso');
        if (1 !== preg_match('/^\d{4}-\d{4}$/', $schoolYear)) {
            $schoolYear = $currentYear;
        } elseif (!\in_array($schoolYear, $years, true)) {
            $years[] = $schoolYear;
        }
        rsort($years);

        $visible = $visibility->visibleTo($tasks->findBySchoolYear($schoolYear), $user, $isAdmin);

        // Opciones del acotado: departamentos, roles y personas presentes en lo visible (sin consulta
        // extra). Las personas se listan por el responsable QUE SE MUESTRA, para que el desplegable y
        // la columna digan lo mismo también en una tarea delegada.
        $departments = $roles = $people = [];
        foreach ($visible as $t) {
            if (null !== ($d = $t->getUnit()?->getName())) {
                $departments[$d] = true;
            }
            if (null !== ($r = self::roleNameOf($t))) {
                $roles[$r] = true;
            }
            if (null !== ($p = $t->resolveResponsible())) {
                $people[$p->getId()] = $p->getFullName();
            }
        }
        $departments = array_keys($departments);
        $roles = array_keys($roles);
        sort($departments);
        sort($roles);
        asort($people);

        // NAVEGAR. Las vistas se ofrecen por CAPACIDAD, no por su recuento: una fila de navegación que
        // aparece y desaparece según los datos no se puede aprender. Para quien no manda en nadie, "Mías"
        // sería idéntica a "Abiertas" (su ámbito visible ES lo suyo) y "Esperando mi validación" nunca
        // podría tener nada, así que solo ve dos. Se resuelve ANTES de acotar porque no depende de los
        // datos, solo de quién mira.
        $supervises = $isAdmin || $hierarchy->commandsWholeSchool($user) || null !== $hierarchy->commandedDepartment($user);
        // Cada vista lleva SU texto de "aquí no hay nada" pegado a su definición. Vivía en un mapa aparte,
        // en la plantilla, y añadir "Devueltas para revisar" sin acordarse de él dejaba la pantalla en un
        // 500 de Twig justo cuando la vista estaba vacía — el peor momento posible.
        $viewDefs = array_filter([
            ['key' => 'abiertas', 'label' => 'Abiertas', 'empty' => 'No hay ninguna tarea abierta. Todo al día.'],
            ['key' => 'mias', 'label' => 'Mías', 'only' => $supervises, 'empty' => 'No tienes tareas abiertas.'],
            ['key' => 'validar', 'label' => 'Esperando mi validación', 'only' => $supervises, 'empty' => 'No hay nada esperando tu validación.'],
            // "Devueltas para revisar" es trabajo que espera a alguien concreto, igual que "Esperando mi
            // validación": sin vista propia, las devueltas quedaban revueltas entre las 42 "Abiertas" y no
            // había forma de ver a quién le toca mover ficha. Se ofrece a todo el mundo, no solo a quien
            // supervisa: una tarea devuelta la tiene que rehacer su responsable.
            ['key' => 'revision', 'label' => 'Devueltas para revisar', 'empty' => 'No hay ninguna tarea devuelta para revisar.'],
            // La CLAVE sigue siendo "vencidas" (vive en la URL y en los enlaces que la gente ya tiene
            // guardados); lo que el centro pidió cambiar es la palabra que se lee.
            ['key' => 'vencidas', 'label' => 'Fuera de plazo', 'tone' => 'warning', 'empty' => 'No hay ninguna tarea fuera de plazo. Todo al día.'],
        ], static fn (array $v): bool => false !== ($v['only'] ?? true));
        // "cerradas" no está en la fila de vistas (se alcanza por el pie): el histórico no compite con
        // el trabajo vivo, que es un tercio de las filas.
        $vista = $request->query->getString('vista');
        if (!\in_array($vista, [...array_column($viewDefs, 'key'), 'cerradas'], true)) {
            $vista = 'abiertas';
        }
        $emptyCopy = 'cerradas' === $vista
            ? 'Todavía no hay tareas cerradas este curso.'
            : array_column($viewDefs, 'empty', 'key')[$vista];

        // ACOTAR. Un valor que no está entre las opciones se descarta: filtrar por algo que no puede
        // casar solo produce un vacío inexplicable.
        $departamento = $request->query->getString('departamento');
        // "nadie" (sin responsable) es un VALOR del filtro de persona, no una vista: pertenece a acotar
        // por persona, y así la fila de navegación se queda en cuatro opciones.
        $persona = $request->query->getString('persona');
        $rol = $request->query->getString('rol');
        $fecha = $request->query->getString('fecha');
        $rawQuery = trim($request->query->getString('q'));
        $q = mb_strtolower($rawQuery);
        if (!\in_array($departamento, $departments, true)) {
            $departamento = '';
        }
        if (self::NOBODY !== $persona && !\array_key_exists($persona, $people)) {
            $persona = '';
        }
        if (!\in_array($rol, $roles, true)) {
            $rol = '';
        }
        // Las ventanas van de hoy hacia delante y "vencida" es hacia atrás: en esa vista la intersección
        // sería vacía siempre, así que la ventana no aplica y tampoco se ofrece.
        $offersDateWindow = 'vencidas' !== $vista;
        if (!$offersDateWindow || !\array_key_exists($fecha, self::DATE_WINDOWS)) {
            $fecha = '';
        }

        $weekEnd = $today->modify('+7 days')->format('Y-m-d');
        $narrows = static function (Task $t) use ($departamento, $persona, $rol, $fecha, $q, $todayStr, $weekEnd): bool {
            if ('' !== $departamento && $t->getUnit()?->getName() !== $departamento) {
                return false;
            }
            $who = $t->resolveResponsible();
            if (self::NOBODY === $persona && null !== $who) {
                return false;
            }
            if ('' !== $persona && self::NOBODY !== $persona && (string) $who?->getId() !== $persona) {
                return false;
            }
            if ('' !== $rol && self::roleNameOf($t) !== $rol) {
                return false;
            }
            $due = $t->getDueDate()->format('Y-m-d');
            $byDate = match ($fecha) {
                '7dias' => $due >= $todayStr && $due <= $weekEnd,
                'adelante' => $due > $weekEnd,
                default => true,
            };
            if (!$byDate) {
                return false;
            }
            if ('' === $q) {
                return true;
            }
            // Busca en el responsable que la fila ENSEÑA (resolveResponsible), no en el asignado: una
            // tarea delegada no se encontraba por el nombre que el usuario está leyendo en pantalla.
            $hay = mb_strtolower($t->getTitle().' '.($who?->getFullName() ?? '').' '.($t->getUnit()?->getName() ?? ''));

            return str_contains($hay, $q);
        };
        $scoped = array_values(array_filter($visible, $narrows));

        $viewMatches = static function (string $view, Task $t) use ($user, $workflows, $today): bool {
            return match ($view) {
                'mias' => !$t->isClosed() && $t->isOwnedBy($user),
                // Se le pregunta al WORKFLOW, que es quien decide si el botón "Validar" existe: reimplementar
                // el predicado aquí ya se tragó la separación de funciones y la vista listaba tareas propias
                // que el guard iba a rechazar (una jefa de departamento supera al rol Tutor/a de su propia
                // tarea, así que pasaba el filtro de jerarquía). Un listado no debe prometer lo que la ficha
                // va a negar.
                'validar' => $workflows->isAwaitingVerdict($t),
                'revision' => TaskStatus::IN_REVIEW === $t->getStatus(),
                'vencidas' => $t->isOverdueOn($today),
                'cerradas' => $t->isClosed(),
                // "Abiertas" es TODO lo abierto del ámbito visible, y es la vista por defecto: cualquier
                // tarea viva tiene que ser alcanzable desde aquí sin acotar nada.
                default => !$t->isClosed(),
            };
        };
        // Cada contador se calcula sobre lo YA acotado, que es lo que hace que una vista entregue siempre
        // el número que promete.
        $views = array_map(
            static fn (array $v): array => [...$v, 'n' => \count(array_filter($scoped, static fn (Task $t): bool => $viewMatches($v['key'], $t)))],
            $viewDefs,
        );

        $filtered = array_values(array_filter($scoped, static fn (Task $t): bool => $viewMatches($vista, $t)));
        // Lo más vencido arriba. Dentro de una vista el estado de cierre es uniforme (todas abiertas o
        // todas cerradas), así que la fecha es la única clave que discrimina; el sort de PHP es estable,
        // de modo que los empates conservan el orden de la consulta.
        usort($filtered, static fn (Task $a, Task $b): int => $a->getDueDate() <=> $b->getDueDate());

        // La agrupación por urgencia es invariante, no un premio por no filtrar (antes desaparecía en
        // cuanto se acotaba, justo cuando más falta hace). En el histórico no aplica: nada vence ya.
        $grouped = 'cerradas' !== $vista;
        $overdue = $upcoming = [];
        if ($grouped) {
            foreach ($filtered as $t) {
                if ($t->isOverdueOn($today)) {
                    $overdue[] = $t;
                } else {
                    $upcoming[] = $t;
                }
            }
        }

        // Todo el estado de la pantalla en un mapa: la plantilla construye CADA enlace partiendo de
        // aquí, así que ningún control puede volver a tirar en silencio los filtros de otro (el
        // buscador y el selector de curso lo hacían).
        $params = [
            'curso' => $schoolYear,
            'vista' => 'abiertas' !== $vista ? $vista : null,
            'departamento' => $departamento ?: null,
            'persona' => $persona ?: null,
            'rol' => $rol ?: null,
            'fecha' => $fecha ?: null,
            'q' => '' !== $rawQuery ? $rawQuery : null,
        ];
        // Acotado activo como etiquetas retirables: cada una se quita sola, sin borrar las demás.
        $personaLabel = match (true) {
            self::NOBODY === $persona => 'Sin asignar',
            '' !== $persona => $people[$persona],
            default => '',
        };
        $tokens = [];
        foreach ([
            ['key' => 'departamento', 'label' => $departamento],
            ['key' => 'persona', 'label' => $personaLabel],
            ['key' => 'rol', 'label' => $rol],
            ['key' => 'fecha', 'label' => self::DATE_WINDOWS[$fecha] ?? ''],
            ['key' => 'q', 'label' => '' !== $rawQuery ? '«'.$rawQuery.'»' : ''],
        ] as $token) {
            if ('' !== $token['label']) {
                $tokens[] = $token;
            }
        }

        return $this->render('task/index.html.twig', [
            'schoolYear' => $schoolYear,
            'years' => $years,
            'todayStr' => $todayStr,
            'params' => $params,
            'views' => $views,
            'vista' => $vista,
            'emptyCopy' => $emptyCopy,
            'tokens' => $tokens,
            'closedCount' => \count(array_filter($scoped, static fn (Task $t): bool => $t->isClosed())),
            'departamento' => $departamento,
            'persona' => $persona,
            'nobody' => self::NOBODY,
            'rol' => $rol,
            'fecha' => $fecha,
            'dateWindows' => $offersDateWindow ? self::DATE_WINDOWS : [],
            'offersDateWindow' => $offersDateWindow,
            'q' => $rawQuery,
            'departmentOptions' => $departments,
            'roleOptions' => $roles,
            'peopleOptions' => $people,
            'grouped' => $grouped,
            'tasks' => $filtered,
            'overdueTasks' => $overdue,
            'upcomingTasks' => $upcoming,
        ]);
    }

    /**
     * The name of the role responsible for a task, or null when it has none. Used by the task list
     * filter (Rol) and to build its options.
     */
    private static function roleNameOf(Task $task): ?string
    {
        return $task->responsibleRole()?->getName();
    }

    /**
     * Creates a task — or ONE PER PERSON, when several are picked. Each user may assign it to themselves
     * or to someone below them in the chain of command (the departments they command by rank); the
     * choices are limited to that set and re-checked on submit.
     *
     * The centre asked to be able to send the same task "a un solo usuario o a un colectivo (todo un
     * departamento o todo el claustro)". That is N independent tasks and not one shared task: each person
     * delivers their own, gets their own comments and their own verdict, and a single row could not hold
     * four different states at once. Leaving the department empty is how "todo el claustro" is said — each
     * task then belongs to its own person's department ({@see TaskFormData::departmentFor()}).
     */
    #[Route('/tareas/nueva', name: 'task_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $user, OrganizationHierarchy $hierarchy, RoleRepository $roles, EntityManagerInterface $entityManager, TaskAssignmentNotifier $assignmentNotifier): Response
    {
        $units = $this->assignableDepartments($user, $hierarchy);
        $roleChoices = $this->assignableRoles($user, $roles->findAllOrdered(), $hierarchy);
        $userChoices = $this->assignableUsers($user, $hierarchy);

        $data = new TaskFormData();
        $data->multiple = true;
        // Prefill the deadline when arriving from the calendar's "+ Nueva tarea" (?fecha=YYYY-MM-DD).
        // An invalid/missing value leaves it empty; a non-teaching day is still caught by the form's
        // lective-day validation on submit. Anchor the midnight in PHP's default time zone — the one
        // the DateType renders the value in and Doctrine hydrates dates in — so the prefilled day is
        // never shifted (a Madrid-anchored midnight shown by a UTC server would render as the day before).
        $data->dueDate = CalendarDate::parse($request->query->getString('fecha'), new \DateTimeZone(date_default_timezone_get()));
        $form = $this->createForm(TaskFormType::class, $data, [
            'assignable_roles' => $roleChoices,
            'assignable_units' => $units,
            'assignable_users' => $userChoices,
            'include_deliverable' => true,
            'multiple_assignees' => true,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->assertResponsibilityAllowed($data, $roleChoices, $units, $userChoices);

            // Lo que hay que entregar también nombra el tipo de tarea: con entregable o simple.
            $type = $data->deliverable->isRequired() ? TaskType::WITH_DELIVERABLE : TaskType::SIMPLE;
            $created = [];
            foreach ($data->responsibleUsers() as $person) {
                $task = new Task($data->title, SchoolYear::current($data->dueDate), $data->dueDate, $type);
                $this->applyFormData($task, $data, $person);
                $task->setCreatedBy($user);
                $entityManager->persist($task);
                $created[] = $task;
            }
            // Un solo flush para todas: crear la tarea de 78 personas no puede ser 78 transacciones.
            $entityManager->flush();

            // Avisa a cada responsable (típicamente un subordinado) de que tiene una tarea nueva. No se
            // auto-notifica si te la creas a ti mismo (ver TaskAssignmentNotifier). En LOTE a propósito:
            // uno a uno serían ochenta flushes y ochenta correos seguidos dentro de esta misma petición.
            $assignmentNotifier->notifyCreatedBatch($created, $user);

            if (1 === \count($created)) {
                $this->addFlash('success', 'Tarea creada.');

                return $this->redirectToRoute('task_show', ['id' => $created[0]->getId()]);
            }

            // Con varias no hay una ficha a la que ir: el listado es la única vista que las enseña juntas.
            $this->addFlash('success', sprintf('Creadas %d tareas, una para cada persona. Ya las tienen avisadas.', \count($created)));

            return $this->redirectToRoute('task_index');
        }

        return $this->render('task/new.html.twig', ['form' => $form]);
    }

    /**
     * Edits a task. Allowed to its creator, a superior of its unit, or an admin. The task type is
     * not editable (it governs the lifecycle already in progress).
     */
    #[Route('/tareas/{id}/editar', name: 'task_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Task $task, Request $request, #[CurrentUser] User $user, OrganizationHierarchy $hierarchy, RoleRepository $roles, EntityManagerInterface $entityManager): Response
    {
        if (!$this->canManage($task, $user, $hierarchy)) {
            throw $this->createAccessDeniedException('No puedes editar esta tarea.');
        }

        $units = $this->assignableDepartments($user, $hierarchy);
        // Keep the task's current department as a valid choice even if now outside the scope.
        $currentUnit = $task->getResponsibility()?->getUnit();
        if (null !== $currentUnit && !\in_array($currentUnit, $units, true)) {
            $units[] = $currentUnit;
        }
        $roleChoices = $this->assignableRoles($user, $roles->findAllOrdered(), $hierarchy);
        // Keep the task's current role as a valid choice even if now outside the scope.
        $currentRole = $task->getResponsibility()?->getRole();
        if (null !== $currentRole && !\in_array($currentRole, $roleChoices, true)) {
            $roleChoices[] = $currentRole;
        }
        $userChoices = $this->assignableUsers($user, $hierarchy);
        // Keep the task's current assignee as a valid choice even if now outside the scope.
        $currentAssignee = $task->getAssignedUser();
        if (null !== $currentAssignee && !\in_array($currentAssignee, $userChoices, true)) {
            $userChoices[] = $currentAssignee;
        }

        $data = TaskFormData::fromTask($task);
        // The deliverable toggle is not shown on edit: the lifecycle is fixed once the task is running.
        $form = $this->createForm(TaskFormType::class, $data, [
            'assignable_roles' => $roleChoices,
            'assignable_units' => $units,
            'assignable_users' => $userChoices,
            'include_deliverable' => false,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->assertResponsibilityAllowed($data, $roleChoices, $units, $userChoices);

            $this->applyFormData($task, $data);
            $entityManager->flush();
            $this->addFlash('success', 'Tarea actualizada.');

            return $this->redirectToRoute('task_show', ['id' => $task->getId()]);
        }

        return $this->render('task/edit.html.twig', ['form' => $form, 'task' => $task]);
    }

    /**
     * Deletes a task. Allowed to its creator, a superior of its unit, or an admin.
     */
    #[Route('/tareas/{id}/borrar', name: 'task_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Task $task, Request $request, #[CurrentUser] User $user, OrganizationHierarchy $hierarchy, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('task_delete'.$task->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        if (!$this->canManage($task, $user, $hierarchy)) {
            throw $this->createAccessDeniedException('No puedes borrar esta tarea.');
        }

        $entityManager->remove($task);
        $entityManager->flush();
        $this->addFlash('success', 'Tarea borrada.');

        return $this->redirectToRoute('task_index');
    }

    #[Route('/tareas/{id}', name: 'task_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(
        Task $task,
        #[CurrentUser] User $user,
        AuditLogRepository $auditLog,
        TaskVisibility $visibility,
        OrganizationHierarchy $hierarchy,
        TaskWorkflow $workflows,
        TaskActivityPresenter $activity,
        TaskReminderNotifier $reminders,
        TaskCommentRepository $comments,
    ): Response {
        // Same organisation-chart scope as the plan and the calendar, enforced here so the detail
        // cannot be reached by guessing an id: only the task's own people, a superior of its unit, or
        // an admin may open it.
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        if (!$visibility->isVisibleTo($task, $user, $isAdmin)) {
            throw $this->createAccessDeniedException('No puedes ver esta tarea.');
        }

        // Everyone who reaches this point (own people, superiors, admins) is exactly who may see the
        // task's activity history, so the timeline is shown to every viewer.
        $canWork = $this->canWorkOn($task, $user);
        // Cancelling is a management action (creator/superior/admin), not "work": it must not be offered
        // to a plain assignee.
        $canManage = $this->canManage($task, $user, $hierarchy);

        // Delegation hands the task DOWN to a subordinate, so solo la ofrece quien es titular de la
        // tarea (isOwnedBy: un jefe de departamento pasando su tarea a un miembro) o un admin — NO un
        // superior de rango superior (un director no delega la tarea de un jefe de departamento). Y solo
        // hacia gente a la que manda, por lo que un miembro raso (que no manda a nadie) nunca la ve.
        // Además solo mientras la tarea sigue Pendiente: una entregada/finalizada/cancelada ya no cambia
        // de titular (reasignarla reescribiría quién la hizo). Y vale el TITULAR aunque ya la haya
        // delegado ({@see Task::concerns()}), para que pueda retirar o cambiar su propia delegación.
        $canDelegate = ($isAdmin || $task->concerns($user)) && $task->isPending();
        $canRemind = $this->canRemind($task, $user, $hierarchy);
        $delegatable = $canDelegate
            ? array_values(array_filter($this->assignableUsers($user, $hierarchy), static fn (User $u): bool => $u !== $user))
            : [];

        $today = new \DateTimeImmutable('today');
        $actions = $this->availableActions($workflows, $task, $canWork, $canManage, $today);
        // La misma consulta sirve dos vistas del mismo hecho: el histórico campo a campo (plegado, para
        // quien audita) y la línea del ciclo de vida (los hitos, arriba). Una sola lectura del rastro.
        $trail = $auditLog->findForSubject('Task', (string) $task->getId());
        $thread = $comments->findThreadFor($task);

        return $this->render('task/show.html.twig', [
            'task' => $task,
            // Quién decide, dónde está la tarea y qué comentarios dejan de ser conversación para ser
            // contenido: resuelto en PHP, porque son preguntas de negocio y no de maquetación.
            'view' => TaskDetailView::of(
                task: $task,
                viewer: $user,
                actions: $actions,
                trail: $trail,
                comments: $thread,
                // Quién dará el visto bueno: el superior INMEDIATO de la tarea (managersAbove viene
                // ordenado de menor a mayor rango). En la cima de la jerarquía no hay ninguno, y la ficha
                // se calla en vez de prometer una validación que nadie puede dar.
                validator: $task->isClosed() ? null : ($hierarchy->managersAbove($task)[0] ?? null),
                today: $today,
            ),
            // El enlace del entregable solo se corrige mientras la tarea está Entregada (a la espera de
            // validación): es EXACTAMENTE la regla que aplica setDeliverable(), aquí para no ofrecer un
            // formulario que el servidor va a rechazar (salía en una tarea ya finalizada).
            'canEditDeliverable' => $canWork && $task->requiresDocument() && $task->isSubmitted(),
            // Editing/deleting is a management action (creator/superior/admin), a different set than
            // "who works on it" — the template gates the Edit link with this.
            'canManage' => $canManage,
            // The lifecycle actions this user may fire now: the workflow's guards already hide the
            // superior-only ones for non-superiors; here we also hide progress ones from outsiders and
            // offer "cancel" only to whoever may manage the task and only while it is still in time.
            'actions' => $actions,
            'canSeeHistory' => true,
            // Only a superior with subordinates gets the delegate control.
            'canDelegate' => $canDelegate && [] !== $delegatable,
            'delegatable' => $delegatable,
            // "Recordar": supervisión, no trabajo. Y si a quien hay que avisar ya se le avisó hoy, la
            // ficha lo dice en vez de ofrecer un botón que el servidor va a frenar (mismo tope).
            'canRemind' => $canRemind,
            'remindedAt' => $canRemind ? $reminders->nudgedTodayAt($task) : null,
            // The trail humanised for non-technical readers; the raw diff rides along for admins only.
            'activityRows' => $activity->present($trail),
            // Comentar suelto lo puede hacer cualquiera que tenga algo que ver con la tarea ("siempre
            // puede hacer comentarios"), pero no un superior de paso que solo está mirando el plan.
            'canComment' => !$task->isClosed() && ($isAdmin || $task->concerns($user) || $canManage),
            'isAdmin' => $isAdmin,
        ]);
    }

    /**
     * Delegates the task to a subordinate (or clears the delegation, restoring the structural
     * responsibility). Es la acción del TITULAR de la tarea (o un admin): un jefe de departamento pasa
     * su tarea a un miembro suyo. Un superior de rango superior NO delega la tarea de un subordinado
     * (supervisa, no reasigna); y solo se delega en alguien a quien se manda.
     */
    #[Route('/tareas/{id}/delegar', name: 'task_delegate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delegate(Task $task, Request $request, #[CurrentUser] User $user, OrganizationHierarchy $hierarchy, UserRepository $users, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('task_delegate'.$task->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        // El TITULAR sigue mandando en su tarea aunque la haya delegado ({@see Task::concerns()}): con
        // `isOwnedBy` a secas, delegar era un viaje sin vuelta — la propiedad pasaba en exclusiva al
        // delegado y el titular no podía ni retirar la delegación que él mismo había puesto.
        if (!$this->isGranted('ROLE_ADMIN') && !$task->concerns($user)) {
            throw $this->createAccessDeniedException('No puedes delegar esta tarea.');
        }
        if (!$task->isPending()) {
            throw $this->createAccessDeniedException('Una tarea ya entregada o cerrada no cambia de titular.');
        }

        $delegateeId = (string) $request->request->get('delegatedTo');
        if ('' === $delegateeId) {
            // Recall: back to the structural responsibility.
            $task->setDelegatedTo(null);
        } else {
            $delegatee = $users->find((int) $delegateeId);
            if (null === $delegatee || $delegatee === $user || !\in_array($delegatee, $this->assignableUsers($user, $hierarchy), true)) {
                throw $this->createAccessDeniedException('No puedes delegar en esa persona.');
            }
            $task->setDelegatedTo($delegatee);
        }
        $entityManager->flush();
        $this->addFlash('success', 'Delegación actualizada.');

        return $this->redirectToRoute('task_show', ['id' => $task->getId()]);
    }

    /**
     * Fires a lifecycle transition (entregar/validar/devolver a revisión/cancelar) chosen from the task
     * detail. "Entregar" (submit) requires the assignee; "validar"/"review" are gated by the workflow
     * guard (superior only); "cancelar" is a management action (creator/superior/admin) and, on top of
     * that, only while the task is still within its deadline ({@see Task::isOverdueOn()}).
     *
     * Every one of them may carry a COMMENT, and "devolver" demands one: what comes back has to say what
     * to change, or the person on the other side is left guessing. The comment is written before the
     * transition is applied so a rejected comment never leaves the task moved with nothing said.
     */
    #[Route('/tareas/{id}/accion/{transition}', name: 'task_transition', requirements: ['id' => '\d+', 'transition' => '[a-z_]+'], methods: ['POST'])]
    public function transition(Task $task, string $transition, Request $request, #[CurrentUser] User $user, TaskWorkflow $workflows, OrganizationHierarchy $hierarchy, EntityManagerInterface $entityManager, FileUploader $uploader, TaskProgressNotifier $progress): Response
    {
        if (!$this->isCsrfTokenValid('task_transition'.$task->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        // Cancel is a management action; validate/review are guarded as superior by the workflow; the
        // rest (submit) require whoever works on the task.
        if ('cancel' === $transition) {
            if (!$this->canManage($task, $user, $hierarchy)) {
                throw $this->createAccessDeniedException('No puedes cancelar esta tarea.');
            }
            // Una tarea FUERA DE PLAZO no se cancela: se entrega, o quien manda la da por finalizada.
            // Petición expresa del centro — cancelar una tarea que ya se ha pasado de fecha es quitársela
            // de encima, y en el histórico queda como "anulada" cuando en realidad no se hizo. Se
            // comprueba aquí y no solo en la pantalla: esconder el botón no es una regla.
            if ($task->isOverdueOn(new \DateTimeImmutable('today'))) {
                $this->addFlash('error', 'Esta tarea está fuera de plazo: ya no se puede cancelar. Entrégala o pide que la den por finalizada.');

                return $this->redirectToRoute('task_show', ['id' => $task->getId()]);
            }
        } elseif (!\in_array($transition, self::SUPERIOR_TRANSITIONS, true) && !$this->canWorkOn($task, $user)) {
            throw $this->createAccessDeniedException('Esta tarea no es tuya.');
        }

        $workflow = $workflows->for($task);
        if (!$workflow->can($task, $transition)) {
            // Not enabled from the current state, or blocked by the guard (e.g. non-superior validating).
            throw $this->createAccessDeniedException('Acción no disponible para esta tarea.');
        }

        // Devolver para revisar SIN decir qué hay que cambiar deja a la otra persona adivinando, así que
        // el comentario es obligatorio justo aquí y opcional en todo lo demás.
        $comment = trim((string) $request->request->get('comentario'));
        if ('review' === $transition && '' === $comment) {
            $this->addFlash('error', 'Escribe qué hay que modificar: es lo que va a leer quien tiene que corregirla.');

            return $this->redirectToRoute('task_show', ['id' => $task->getId()]);
        }
        if (mb_strlen($comment) > self::COMMENT_MAX) {
            $this->addFlash('error', sprintf('El comentario es demasiado largo (máximo %d caracteres).', self::COMMENT_MAX));

            return $this->redirectToRoute('task_show', ['id' => $task->getId()]);
        }

        // Entregar: lo que la tarea exija se adjunta en el MISMO paso (no hay un estado intermedio donde
        // ponerlo antes). Un fallo aquí devuelve a la ficha con la tarea intacta.
        if ('submit' === $transition) {
            $error = $this->collectDeliverable($task, $request, $uploader);
            if (null !== $error) {
                $this->addFlash('error', $error);

                return $this->redirectToRoute('task_show', ['id' => $task->getId()]);
            }
        }

        if ('' !== $comment) {
            $entityManager->persist(new TaskComment($task, $user, $comment, $transition));
        }

        $workflow->apply($task, $transition);
        $entityManager->flush();

        // Cada vuelta del ciclo avisa a la otra parte: entregada → a quien la mandó; devuelta → a quien
        // la tiene que corregir; finalizada → a quien la hizo, que es cuando le desaparece del panel.
        $progress->notify($task, $transition, $user, '' !== $comment ? $comment : null);

        $this->addFlash('success', match ($transition) {
            'submit' => 'Entregada. Avisamos a quien tiene que revisarla.',
            'review' => 'Devuelta para revisar. Ya sabe qué tiene que cambiar.',
            'validate' => 'Tarea finalizada.',
            default => 'Tarea actualizada.',
        });

        return $this->redirectToRoute('task_show', ['id' => $task->getId()]);
    }

    /**
     * Reads what was handed in with a delivery (a link, a file, or either) and puts it on the task,
     * checking it against what the task demands ({@see Task::getDeliverable()}).
     *
     * @param Task         $task     the task being delivered
     * @param Request      $request  the submit request
     * @param FileUploader $uploader the private-storage uploader
     *
     * @return string|null an error to show, or null when everything is in place
     */
    private function collectDeliverable(Task $task, Request $request, FileUploader $uploader): ?string
    {
        $requirement = $task->getDeliverable();
        $reference = trim((string) $request->request->get('reference'));
        $file = $request->files->get('archivo');

        if ('' !== $reference) {
            if (!$requirement->acceptsLink()) {
                return 'Esta tarea se entrega con un archivo, no con un enlace.';
            }
            if (mb_strlen($reference) > 255) {
                return 'El enlace es demasiado largo (máximo 255 caracteres).';
            }
            $task->setDeliverableReference($reference);
        }

        if ($file instanceof UploadedFile && DocumentUpload::isPresent($file)) {
            if (!$requirement->acceptsFile()) {
                return 'Esta tarea se entrega con un enlace, no con un archivo.';
            }
            // Misma política que el resto de documentos del centro (tamaño y extensiones): lo que la
            // conserjería acepta imprimir y lo que se entrega en una tarea no pueden ser cosas distintas.
            $problem = DocumentUpload::problem($file);
            if (null !== $problem) {
                return $problem;
            }
            // El anterior se borra del disco: con las vueltas de revisión sin tope, cada reentrega dejaría
            // huérfano el archivo de la vuelta anterior y el almacén crecería sin fin. Se borra DESPUÉS de
            // subir el nuevo, para no quedarse sin ninguno de los dos si la subida falla.
            $previous = $task->getDeliverableFilePath();
            $task->attachDeliverableFile($uploader->upload($file, self::DELIVERABLE_SUBDIR), DocumentUpload::nameOf($file));
            if (null !== $previous) {
                $uploader->remove($previous);
            }
        }

        // "Requiere algo" se comprueba contra lo que la tarea TIENE, no contra lo que llega ahora: una
        // tarea devuelta para revisar ya trae su entregable de la vuelta anterior, y obligar a volver a
        // subirlo para cambiar una coma sería absurdo.
        if ($requirement->isRequired() && !$task->hasDeliverable()) {
            return match ($requirement) {
                DeliverableRequirement::LINK => 'Pega el enlace del documento para entregar la tarea.',
                DeliverableRequirement::FILE => 'Sube el archivo para entregar la tarea.',
                default => 'Pega un enlace o sube un archivo para entregar la tarea.',
            };
        }

        return null;
    }

    /**
     * Nudges whoever has to do the task ("Recordar"): an in-app notice + e-mail + push, on demand. It is
     * a SUPERVISION action — offered to whoever answers for the task without having to do it (its
     * manager, or the titular who delegated it), never to the person who owes the work (nudging
     * yourself is noise) nor on a closed task.
     *
     * The one-a-day cap lives in the notifier, shared with the nightly engine, so this endpoint cannot
     * be turned into a spam button by reloading it.
     */
    #[Route('/tareas/{id}/recordar', name: 'task_remind', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function remind(Task $task, Request $request, #[CurrentUser] User $user, OrganizationHierarchy $hierarchy, TaskReminderNotifier $reminders): Response
    {
        if (!$this->isCsrfTokenValid('task_remind'.$task->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        if (!$this->canRemind($task, $user, $hierarchy)) {
            throw $this->createAccessDeniedException('No puedes mandar un recordatorio de esta tarea.');
        }

        $notified = $reminders->nudge($task);
        if (null === $notified) {
            // O ya se avisó hoy (el tope) o no hay a quién avisar: decir CUÁL de las dos, en vez de un
            // "listo" que no hizo nada.
            $this->addFlash('error', null === $reminders->nudgeRecipient($task)
                ? 'Esta tarea no tiene a nadie a quien avisar.'
                : 'Ya se avisó hoy de esta tarea.');

            return $this->redirectToRoute('task_show', ['id' => $task->getId()]);
        }

        $this->addFlash('success', sprintf('Recordatorio enviado a %s.', $notified->getFullName()));

        return $this->redirectToRoute('task_show', ['id' => $task->getId()]);
    }

    /**
     * Sets or clears the deliverable reference (an opaque link/code to the document in the school's
     * cloud — never the content). Only whoever works on the task, and only if it expects a document.
     */
    #[Route('/tareas/{id}/entregable', name: 'task_set_deliverable', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function setDeliverable(Task $task, Request $request, #[CurrentUser] User $user, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('task_deliverable'.$task->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        if (!$this->canWorkOn($task, $user)) {
            throw $this->createAccessDeniedException('Esta tarea no es tuya.');
        }
        if (!$task->requiresDocument()) {
            throw $this->createNotFoundException('Esta tarea no lleva entregable.');
        }
        // Solo se corrige el enlace mientras está Entregada (a la espera de validación): al entregar ya
        // se adjunta, y una tarea finalizada/cancelada/pendiente no se toca por aquí. Misma condición
        // que decide si la vista ofrece el formulario (canEditDeliverable en show()).
        if (!$task->isSubmitted()) {
            throw $this->createAccessDeniedException('Solo se puede editar el entregable de una tarea entregada.');
        }

        $reference = trim((string) $request->request->get('reference'));
        if (mb_strlen($reference) > 255) {
            $this->addFlash('error', 'La referencia es demasiado larga (máximo 255 caracteres).');

            return $this->redirectToRoute('task_show', ['id' => $task->getId()]);
        }

        $task->setDeliverableReference('' !== $reference ? $reference : null);
        $entityManager->flush();
        $this->addFlash('success', 'Entregable actualizado.');

        return $this->redirectToRoute('task_show', ['id' => $task->getId()]);
    }

    /**
     * Adds a comment to the task on its own, without moving it. "Siempre puede hacer comentarios": a
     * question about the delivery, or an answer to it, does not have to wait for a transition.
     *
     * Open to whoever the task concerns and to whoever manages it — not to a superior who is merely
     * browsing the course plan, which is the same line {@see show()} draws for `canComment`.
     */
    #[Route('/tareas/{id}/comentario', name: 'task_comment', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function comment(Task $task, Request $request, #[CurrentUser] User $user, OrganizationHierarchy $hierarchy, EntityManagerInterface $entityManager, TaskVisibility $visibility): Response
    {
        if (!$this->isCsrfTokenValid('task_comment'.$task->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        if (!$visibility->isVisibleTo($task, $user, $isAdmin)) {
            throw $this->createAccessDeniedException('No puedes ver esta tarea.');
        }
        if (!$isAdmin && !$task->concerns($user) && !$this->canManage($task, $user, $hierarchy)) {
            throw $this->createAccessDeniedException('Esta tarea no es tuya.');
        }
        if ($task->isClosed()) {
            // Una tarea cerrada es de solo lectura, igual que para todo lo demás: su hilo es el registro
            // de cómo se llegó hasta ahí, no un tablón que siga creciendo después.
            throw $this->createAccessDeniedException('Esta tarea ya está cerrada.');
        }

        $body = trim((string) $request->request->get('comentario'));
        if ('' === $body || mb_strlen($body) > self::COMMENT_MAX) {
            $this->addFlash('error', '' === $body ? 'Escribe algo antes de enviar el comentario.' : sprintf('El comentario es demasiado largo (máximo %d caracteres).', self::COMMENT_MAX));

            return $this->redirectToRoute('task_show', ['id' => $task->getId()]);
        }

        $entityManager->persist(new TaskComment($task, $user, $body));
        $entityManager->flush();
        $this->addFlash('success', 'Comentario añadido.');

        return $this->redirectToRoute('task_show', ['id' => $task->getId()]);
    }

    /**
     * Serves the file handed in with a task, as an attachment and only to the people the task concerns
     * ({@see TaskVisibility}). It is stored outside the web root, so this route IS the access control:
     * there is no URL that reaches the file without passing through here.
     */
    #[Route('/tareas/{id}/entregable/archivo', name: 'task_deliverable_download', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function downloadDeliverable(Task $task, #[CurrentUser] User $user, TaskVisibility $visibility, FileUploader $uploader): Response
    {
        if (!$visibility->isVisibleTo($task, $user, $this->isGranted('ROLE_ADMIN'))) {
            throw $this->createAccessDeniedException('No puedes ver esta tarea.');
        }

        $path = $task->getDeliverableFilePath();
        if (null === $path) {
            throw $this->createNotFoundException('Esta tarea no tiene archivo entregado.');
        }

        return $this->file($uploader->absolutePath($path), $task->getDeliverableFileName() ?? 'documento');
    }

    /**
     * The lifecycle transitions to offer as buttons: those enabled now, keeping the superior-only ones
     * (validate/review); "submit" (Entregar) for whoever works on the task; and "cancel" only for
     * whoever may manage it AND while the task is still within its deadline.
     *
     * @return list<string> the transition names to show
     */
    private function availableActions(TaskWorkflow $workflows, Task $task, bool $canWork, bool $canManage, \DateTimeImmutable $today): array
    {
        $names = array_map(
            static fn ($transition): string => $transition->getName(),
            $workflows->for($task)->getEnabledTransitions($task),
        );

        return array_values(array_filter(
            $names,
            fn (string $name): bool => match (true) {
                'cancel' === $name => $canManage && !$task->isOverdueOn($today),
                \in_array($name, self::SUPERIOR_TRANSITIONS, true) => true,
                default => $canWork,
            },
        ));
    }

    /**
     * One-click "done" from the agenda: toggles the assignee's progress checkbox (distinct from the
     * superior's validation, which is a workflow transition). Only the assignee, a holder of the
     * task's role, or an admin may do it.
     */
    #[Route('/tareas/{id}/hecho', name: 'task_toggle_done', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggleDone(Task $task, Request $request, #[CurrentUser] User $user, EntityManagerInterface $entityManager, TickOutcome $tick): Response
    {
        if (!$this->isCsrfTokenValid('toggle_done'.$task->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        if (!$this->canWorkOn($task, $user)) {
            throw $this->createAccessDeniedException('Esta tarea no es tuya.');
        }
        if ($task->isClosed()) {
            // Finalizada o cancelada: de solo lectura. Marcar/desmarcar el progreso de algo ya cerrado
            // solo podría contradecir al histórico.
            throw $this->createAccessDeniedException('Esta tarea ya está cerrada.');
        }
        if (!$task->requiresCheckbox()) {
            // This task does not close via the progress checkbox (e.g. it is validated by deliverable).
            throw $this->createNotFoundException('Esta tarea no usa casilla de progreso.');
        }

        $task->setCheckboxDone(!$task->isCheckboxDone());
        $entityManager->flush();

        // A ticked task LEAVES the "hoy/vencidas" list of Inicio (it moves to the done bucket), so the
        // row just vanishes: the toast is the only trace of what happened and the only way back.
        $this->addFlash(TickOutcome::FLASH, $tick->flashFor('task', (int) $task->getId(), $task->getTitle(), $task->isCheckboxDone(), $request));

        // Back to the surface the tick was on ({@see TickOutcome}): Inicio, or the calendar day you were
        // looking at.
        [$route, $parameters] = $tick->routeFor($request);

        return $this->redirectToRoute($route, $parameters);
    }

    /**
     * Copies the common editable fields from the form data onto the task (title, dates, flags,
     * assignee and its unit). Does NOT touch the type — that governs the lifecycle. The responsible
     * role coexists with the assignee (the role is the structural function from the template; the
     * person is a concrete assignee on top of it) and is leadership-only: it is written only when
     * $applyRole is true, so a routine edit by anyone else leaves it untouched.
     */
    private function applyFormData(Task $task, TaskFormData $data, ?User $person = null): void
    {
        \assert(null !== $data->dueDate && null !== $data->responsibilityRole);
        $task->setTitle($data->title)
            ->setDescription($data->description)
            ->setDueDate($data->dueDate)
            ->setSchoolYear(SchoolYear::current($data->dueDate))
            ->setMandatory($data->mandatory)
            // requiresCheckbox is NOT touched: the form does not edit it (see TaskFormData), so whatever
            // the task template set must survive an edit untouched.
            ->setDeliverable($data->deliverable);

        // Responsibility = role + (department, only for per-department roles): the structural backbone,
        // resolved live. The department is also the task's unit context for hierarchy/escalation. The
        // concrete responsible person chosen in the cascade is the assignee.
        //
        // The department comes from {@see TaskFormData::departmentFor()} and not straight from the form:
        // creating for several people at once may leave it empty ("todos los departamentos"), and then
        // each task belongs to its own person's department.
        $person ??= $data->responsibilityUser;
        $unit = null !== $person ? $data->departmentFor($person) : ($data->responsibilityRole->isPerDepartment() ? $data->responsibilityUnit : null);
        $task->setResponsibility(new TaskResponsibility($data->responsibilityRole, $unit))->setUnit($unit);
        $task->setAssignedUser($person);
    }

    /**
     * Guards the responsibility server-side on top of the form's own choice lists: the role must be one
     * the creator commands (or holds), a per-department role must target a department the creator may
     * use, and the chosen person must be within the creator's assignable scope.
     *
     * @param list<Role>       $assignableRoles the roles the creator may set as responsibility
     * @param list<Department> $assignableUnits the departments the creator may target
     * @param list<User>       $assignableUsers the people the creator may assign to
     */
    private function assertResponsibilityAllowed(TaskFormData $data, array $assignableRoles, array $assignableUnits, array $assignableUsers): void
    {
        if (null !== $data->responsibilityRole && !\in_array($data->responsibilityRole, $assignableRoles, true)) {
            throw $this->createAccessDeniedException('No puedes asignar la tarea a ese rol.');
        }

        // El departamento vacío es legítimo al crear para varias personas ("todos los departamentos"):
        // lo que se comprueba entonces es cada persona, una a una, contra el ámbito de quien crea.
        if (null !== $data->responsibilityRole
            && $data->responsibilityRole->isPerDepartment()
            && null !== $data->responsibilityUnit
            && !\in_array($data->responsibilityUnit, $assignableUnits, true)) {
            throw $this->createAccessDeniedException('No puedes asignar la tarea a ese departamento.');
        }

        foreach ($data->responsibleUsers() as $person) {
            if (!\in_array($person, $assignableUsers, true)) {
                throw $this->createAccessDeniedException(sprintf('No puedes asignar la tarea a %s.', $person->getFullName()));
            }
        }
    }

    /**
     * The departments a user may target as a task's responsibility: those they command (superior of)
     * plus their own, so a member can still set a task for a role within their own department.
     *
     * @return list<Department> the assignable departments
     */
    private function assignableDepartments(User $user, OrganizationHierarchy $hierarchy): array
    {
        $departments = $hierarchy->commandedDepartments($user);
        // Plus the user's own department, so a plain member can still set a task for themselves in it.
        $own = $user->getUnit();
        if (null !== $own && !\in_array($own, $departments, true)) {
            $departments[] = $own;
        }

        return $departments;
    }

    /**
     * The people a user may assign tasks to: everyone in the departments they command, plus themselves.
     *
     * @return list<User> the assignable users
     */
    private function assignableUsers(User $user, OrganizationHierarchy $hierarchy): array
    {
        return [...$hierarchy->commandedPeople($user), $user];
    }

    /**
     * The roles a user may set as a task's responsibility, filtered by the chain of command: their own
     * roles (a task for their own function) plus any role they outrank in a scope they command. A plain
     * member (docente/tutor) gets only the roles they hold — so they can only create tasks for
     * themselves; a jefe de departamento also gets the roles below them in their department; a
     * whole-school superior gets everything they outrank. The department is guarded separately.
     *
     * @param User      $user     the creator
     * @param list<Role> $allRoles the full role catalog to filter
     * @param OrganizationHierarchy $hierarchy the chain-of-command service
     *
     * @return list<Role> the roles the user may assign
     */
    private function assignableRoles(User $user, array $allRoles, OrganizationHierarchy $hierarchy): array
    {
        return array_values(array_filter($allRoles, fn (Role $role): bool => $this->mayAssignRole($user, $role, $hierarchy)));
    }

    /**
     * Whether the user may set the given role as a task's responsibility: they hold it themselves (a
     * task for their own function) or they outrank it in scope — the department for a per-department
     * role, centre-wide otherwise (so a per-department rank can never reach a centre-wide role).
     *
     * @param User                  $user      the creator
     * @param Role                  $role      the candidate responsibility role
     * @param OrganizationHierarchy $hierarchy the chain-of-command service
     *
     * @return bool true if the user may assign the role
     */
    private function mayAssignRole(User $user, Role $role, OrganizationHierarchy $hierarchy): bool
    {
        if ($user->holdsRole($role)) {
            return true;
        }

        $scope = $role->isPerDepartment() ? $user->getUnit() : null;

        return $hierarchy->outranks($user, $role, $scope);
    }

    /**
     * Whether the user may nudge the task's people ("Recordar"). Supervision, so: only on a task that is
     * still open, only for someone who does NOT owe the work (you do not remind yourself), and only if
     * they manage it or it is their own task delegated down ({@see Task::concerns()}).
     */
    private function canRemind(Task $task, User $user, OrganizationHierarchy $hierarchy): bool
    {
        if ($task->isClosed() || $task->isOwnedBy($user)) {
            return false;
        }

        return $this->canManage($task, $user, $hierarchy) || $task->concerns($user);
    }

    /**
     * Whether the user may edit/delete the task: its creator, a superior of its unit, or an admin.
     */
    private function canManage(Task $task, User $user, OrganizationHierarchy $hierarchy): bool
    {
        return $this->isGranted('ROLE_ADMIN') || $task->getCreatedBy() === $user || $hierarchy->isSuperiorOfTask($user, $task);
    }

    /**
     * Whether the user may DO the task (entregar, adjuntar entregable, marcar hecho): it is theirs
     * (their person, one of their roles', or delegated to them) or they are an admin. A superior by
     * rank is deliberately NOT included: su papel sobre la tarea de un subordinado es supervisar
     * (validar/devolver, vía el guard del workflow), no ejecutar — si además ejecutara, se cargaría la
     * separación de funciones. Un superior que quiera hacerla él se la delega o se la reasigna.
     */
    private function canWorkOn(Task $task, User $user): bool
    {
        return $this->isGranted('ROLE_ADMIN') || $task->isOwnedBy($user);
    }
}
