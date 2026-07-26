<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Role;
use App\Entity\Task;
use App\Entity\TaskResponsibility;
use App\Entity\Department;
use App\Entity\User;
use App\Enum\TaskType;
use App\Form\TaskFormData;
use App\Form\TaskFormType;
use App\Repository\AuditLogRepository;
use App\Repository\RoleRepository;
use App\Repository\TaskRepository;
use App\Repository\DepartmentRepository;
use App\Repository\UserRepository;
use App\Service\OrganizationHierarchy;
use App\Service\TaskAssignmentNotifier;
use App\Service\TaskVisibility;
use App\Service\TaskWorkflow;
use App\Support\TaskActivityPresenter;
use App\Support\TaskStatus;
use App\Support\TickOutcome;
use App\Util\CalendarDate;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
    private const array SUPERIOR_TRANSITIONS = ['validate', 'reject'];

    /** The deadline windows of the narrowing form, aligned with the buckets Inicio already speaks. */
    private const array DATE_WINDOWS = [
        '7dias' => 'Próximos 7 días',
        'adelante' => 'Más adelante',
    ];

    /** The "persona" value meaning "nobody is on the hook for it": tasks with no resolvable responsible. */
    private const string NOBODY = 'nadie';

    /**
     * The course plan, scoped to the tasks the user may see: their own, those under a unit they are a
     * superior of, or every task for an admin (see {@see TaskVisibility}). The page itself is open to
     * any authenticated user; the organisation chart decides what shows up, not the permission matrix.
     *
     * The screen separates two things that used to be tangled: NAVIGATING (the named views, which are
     * the questions people actually arrive with) and NARROWING (one form: departamento, persona, rol,
     * fecha, búsqueda). Every count is computed over the narrowed set, so a view always delivers the
     * number it promises — the old status chips counted the whole course and lied as soon as anything
     * was filtered.
     */
    #[Route('/tareas', name: 'task_index', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] User $user, TaskRepository $tasks, TaskVisibility $visibility, OrganizationHierarchy $hierarchy): Response
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
        $viewDefs = array_filter([
            ['key' => 'abiertas', 'label' => 'Abiertas'],
            ['key' => 'mias', 'label' => 'Mías', 'only' => $supervises],
            ['key' => 'validar', 'label' => 'Esperando mi validación', 'only' => $supervises],
            ['key' => 'vencidas', 'label' => 'Vencidas', 'tone' => 'warning'],
        ], static fn (array $v): bool => false !== ($v['only'] ?? true));
        // "cerradas" no está en la fila de vistas (se alcanza por el pie): el histórico no compite con
        // el trabajo vivo, que es un tercio de las filas.
        $vista = $request->query->getString('vista');
        if (!\in_array($vista, [...array_column($viewDefs, 'key'), 'cerradas'], true)) {
            $vista = 'abiertas';
        }

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

        $viewMatches = static function (string $view, Task $t) use ($user, $isAdmin, $hierarchy, $today): bool {
            return match ($view) {
                'mias' => !$t->isClosed() && $t->isOwnedBy($user),
                'validar' => TaskStatus::SUBMITTED === $t->getStatus() && ($isAdmin || $hierarchy->isSuperiorOfTask($user, $t)),
                'vencidas' => self::isOverdue($t, $today),
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
                if (self::isOverdue($t, $today)) {
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
     * The name of the role responsible for a task: its responsibility's role, or a legacy assigned role.
     * Null when neither is set. Used by the task list filter (Rol) and its options.
     */
    private static function roleNameOf(Task $task): ?string
    {
        return $task->getResponsibility()?->getRole()->getName() ?? $task->getAssignedRole()?->getName();
    }

    /**
     * A task is overdue when it is still open (neither finalized nor cancelled) and its deadline has
     * passed. |date-free comparison as strings to avoid timezone drift (see CI-UTC memory).
     */
    private static function isOverdue(Task $task, \DateTimeImmutable $today): bool
    {
        return !\in_array($task->getStatus(), TaskStatus::CLOSED, true)
            && $task->getDueDate()->format('Y-m-d') < $today->format('Y-m-d');
    }

    /**
     * Creates a task. Each user may assign it to themselves or to someone below them in the chain of
     * command (the departments they command by rank); the choices are
     * limited to that set and re-checked on submit.
     */
    #[Route('/tareas/nueva', name: 'task_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $user, OrganizationHierarchy $hierarchy, RoleRepository $roles, UserRepository $users, DepartmentRepository $unitRepository, EntityManagerInterface $entityManager, TaskAssignmentNotifier $assignmentNotifier): Response
    {
        $units = $this->assignableDepartments($user, $hierarchy, $unitRepository);
        $roleChoices = $this->assignableRoles($user, $roles->findAllOrdered(), $hierarchy);
        $userChoices = $this->assignableUsers($user, $hierarchy, $users, $unitRepository);

        $data = new TaskFormData();
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
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->assertResponsibilityAllowed($data, $roleChoices, $units, $userChoices);

            // The deliverable toggle also picks the lifecycle: a deliverable task carries the
            // progress/submission/validation flow; a plain one is the simple do-and-validate lifecycle.
            $type = $data->requiresDocument ? TaskType::WITH_DELIVERABLE : TaskType::SIMPLE;
            $task = new Task($data->title, SchoolYear::current($data->dueDate), $data->dueDate, $type);
            $this->applyFormData($task, $data);
            $task->setCreatedBy($user);
            $entityManager->persist($task);
            $entityManager->flush();
            // Avisa al responsable (típicamente un subordinado) de que tiene una tarea nueva. No se
            // auto-notifica si te la creas a ti mismo (ver TaskAssignmentNotifier).
            $assignmentNotifier->notifyCreated($task, $user);
            $this->addFlash('success', 'Tarea creada.');

            return $this->redirectToRoute('task_show', ['id' => $task->getId()]);
        }

        return $this->render('task/new.html.twig', ['form' => $form]);
    }

    /**
     * Edits a task. Allowed to its creator, a superior of its unit, or an admin. The task type is
     * not editable (it governs the lifecycle already in progress).
     */
    #[Route('/tareas/{id}/editar', name: 'task_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Task $task, Request $request, #[CurrentUser] User $user, OrganizationHierarchy $hierarchy, RoleRepository $roles, UserRepository $users, DepartmentRepository $unitRepository, EntityManagerInterface $entityManager): Response
    {
        if (!$this->canManage($task, $user, $hierarchy)) {
            throw $this->createAccessDeniedException('No puedes editar esta tarea.');
        }

        $units = $this->assignableDepartments($user, $hierarchy, $unitRepository);
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
        $userChoices = $this->assignableUsers($user, $hierarchy, $users, $unitRepository);
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
        UserRepository $users,
        DepartmentRepository $unitRepository,
        TaskWorkflow $workflows,
        TaskActivityPresenter $activity,
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
        // de titular (reasignarla reescribiría quién la hizo).
        $canDelegate = ($isAdmin || $task->isOwnedBy($user)) && $task->isPending();
        $delegatable = $canDelegate
            ? array_values(array_filter($this->assignableUsers($user, $hierarchy, $users, $unitRepository), static fn (User $u): bool => $u !== $user))
            : [];

        return $this->render('task/show.html.twig', [
            'task' => $task,
            // El enlace del entregable solo se corrige mientras la tarea está Entregada (a la espera de
            // validación): es EXACTAMENTE la regla que aplica setDeliverable(), aquí para no ofrecer un
            // formulario que el servidor va a rechazar (salía en una tarea ya finalizada).
            'canEditDeliverable' => $canWork && $task->requiresDocument() && $task->isSubmitted(),
            // Editing/deleting is a management action (creator/superior/admin), a different set than
            // "who works on it" — the template gates the Edit link with this.
            'canManage' => $canManage,
            // The lifecycle actions this user may fire now: the workflow's guards already hide the
            // superior-only ones for non-superiors; here we also hide progress ones from outsiders and
            // offer "cancel" only to whoever may manage the task.
            'actions' => $this->availableActions($workflows, $task, $canWork, $canManage),
            'canSeeHistory' => true,
            // Only a superior with subordinates gets the delegate control.
            'canDelegate' => $canDelegate && [] !== $delegatable,
            'delegatable' => $delegatable,
            // The trail humanised for non-technical readers; the raw diff rides along for admins only.
            'activityRows' => $activity->present($auditLog->findForSubject('Task', (string) $task->getId())),
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
    public function delegate(Task $task, Request $request, #[CurrentUser] User $user, OrganizationHierarchy $hierarchy, UserRepository $users, DepartmentRepository $unitRepository, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('task_delegate'.$task->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        if (!$this->isGranted('ROLE_ADMIN') && !$task->isOwnedBy($user)) {
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
            if (null === $delegatee || $delegatee === $user || !\in_array($delegatee, $this->assignableUsers($user, $hierarchy, $users, $unitRepository), true)) {
                throw $this->createAccessDeniedException('No puedes delegar en esa persona.');
            }
            $task->setDelegatedTo($delegatee);
        }
        $entityManager->flush();
        $this->addFlash('success', 'Delegación actualizada.');

        return $this->redirectToRoute('task_show', ['id' => $task->getId()]);
    }

    /**
     * Fires a lifecycle transition (entregar/validar/devolver/cancelar) chosen from the task detail.
     * "Entregar" (submit) requires the assignee; "validar"/"devolver" are gated by the workflow guard
     * (superior only); "cancelar" is a management action (creator/superior/admin).
     */
    #[Route('/tareas/{id}/accion/{transition}', name: 'task_transition', requirements: ['id' => '\d+', 'transition' => '[a-z_]+'], methods: ['POST'])]
    public function transition(Task $task, string $transition, Request $request, #[CurrentUser] User $user, TaskWorkflow $workflows, OrganizationHierarchy $hierarchy, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('task_transition'.$task->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        // Cancel is a management action; validate/reject are guarded as superior by the workflow; the
        // rest (submit) require whoever works on the task.
        if ('cancel' === $transition) {
            if (!$this->canManage($task, $user, $hierarchy)) {
                throw $this->createAccessDeniedException('No puedes cancelar esta tarea.');
            }
        } elseif (!\in_array($transition, self::SUPERIOR_TRANSITIONS, true) && !$this->canWorkOn($task, $user)) {
            throw $this->createAccessDeniedException('Esta tarea no es tuya.');
        }

        $workflow = $workflows->for($task);
        if (!$workflow->can($task, $transition)) {
            // Not enabled from the current state, or blocked by the guard (e.g. non-superior validating).
            throw $this->createAccessDeniedException('Acción no disponible para esta tarea.');
        }

        // Entregar: si la tarea lleva entregable, la referencia del documento se adjunta en el MISMO
        // paso (no hay un estado intermedio donde ponerla antes), y es obligatoria.
        if ('submit' === $transition && $task->requiresDocument()) {
            $reference = trim((string) $request->request->get('reference'));
            if ('' === $reference) {
                $this->addFlash('error', 'Adjunta el enlace del documento para entregar la tarea.');

                return $this->redirectToRoute('task_show', ['id' => $task->getId()]);
            }
            if (mb_strlen($reference) > 255) {
                $this->addFlash('error', 'La referencia es demasiado larga (máximo 255 caracteres).');

                return $this->redirectToRoute('task_show', ['id' => $task->getId()]);
            }
            $task->setDeliverableReference($reference);
        }

        $workflow->apply($task, $transition);
        $entityManager->flush();
        $this->addFlash('success', 'Tarea actualizada.');

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
     * The lifecycle transitions to offer as buttons: those enabled now, keeping the superior-only ones
     * (validate/reject); "submit" (Entregar) for whoever works on the task; and "cancel" only for
     * whoever may manage it.
     *
     * @return list<string> the transition names to show
     */
    private function availableActions(TaskWorkflow $workflows, Task $task, bool $canWork, bool $canManage): array
    {
        $names = array_map(
            static fn ($transition): string => $transition->getName(),
            $workflows->for($task)->getEnabledTransitions($task),
        );

        return array_values(array_filter(
            $names,
            fn (string $name): bool => match (true) {
                'cancel' === $name => $canManage,
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

        // Back to the surface the tick was on ({@see TickOutcome}): Inicio, or the calendar day you were
        // looking at. A ticked task leaves the "hoy/vencidas" list of Inicio (it moves to the done
        // bucket), so there is no stable row to anchor to — landing on the page is enough.
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
    private function applyFormData(Task $task, TaskFormData $data): void
    {
        \assert(null !== $data->dueDate && null !== $data->responsibilityRole);
        $task->setTitle($data->title)
            ->setDescription($data->description)
            ->setDueDate($data->dueDate)
            ->setSchoolYear(SchoolYear::current($data->dueDate))
            ->setMandatory($data->mandatory)
            ->setRequiresCheckbox($data->requiresCheckbox)
            ->setRequiresDocument($data->requiresDocument);

        // Responsibility = role + (department, only for per-department roles): the structural backbone,
        // resolved live. The department is also the task's unit context for hierarchy/escalation. The
        // concrete responsible person chosen in the cascade is the assignee.
        $role = $data->responsibilityRole;
        $unit = $role->isPerDepartment() ? $data->responsibilityUnit : null;
        $task->setResponsibility(new TaskResponsibility($role, $unit))->setUnit($unit);
        $task->setAssignedUser($data->responsibilityUser);
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

        if (null !== $data->responsibilityRole
            && $data->responsibilityRole->isPerDepartment()
            && !\in_array($data->responsibilityUnit, $assignableUnits, true)) {
            throw $this->createAccessDeniedException('No puedes asignar la tarea a ese departamento.');
        }

        if (null !== $data->responsibilityUser && !\in_array($data->responsibilityUser, $assignableUsers, true)) {
            throw $this->createAccessDeniedException('No puedes asignar la tarea a esa persona.');
        }
    }

    /**
     * The departments a user may target as a task's responsibility: those they command (superior of)
     * plus their own, so a member can still set a task for a role within their own department.
     *
     * @return list<Department> the assignable departments
     */
    private function assignableDepartments(User $user, OrganizationHierarchy $hierarchy, DepartmentRepository $units): array
    {
        $departments = $this->commandedDepartments($user, $hierarchy, $units);
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
    private function assignableUsers(User $user, OrganizationHierarchy $hierarchy, UserRepository $users, DepartmentRepository $units): array
    {
        $list = $users->findActiveInUnits($this->commandedDepartments($user, $hierarchy, $units));
        if (!\in_array($user, $list, true)) {
            $list[] = $user;
        }

        return $list;
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
     * The departments a user commands: all of them for a whole-school superior (dirección, jefatura de
     * estudios), just their own for a jefe de departamento, none for a plain member. Derived from the
     * user's ranked roles, never from a unit's manager.
     *
     * @return list<Department> the commanded departments
     */
    private function commandedDepartments(User $user, OrganizationHierarchy $hierarchy, DepartmentRepository $units): array
    {
        if ($hierarchy->commandsWholeSchool($user)) {
            return $units->findActiveDepartments();
        }

        $department = $hierarchy->commandedDepartment($user);

        return null !== $department ? [$department] : [];
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
