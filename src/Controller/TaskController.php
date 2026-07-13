<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Role;
use App\Entity\Task;
use App\Entity\TaskResponsibility;
use App\Entity\Unit;
use App\Entity\User;
use App\Enum\TaskType;
use App\Form\TaskFormData;
use App\Form\TaskFormType;
use App\Repository\AuditLogRepository;
use App\Repository\RoleRepository;
use App\Repository\TaskRepository;
use App\Repository\UserRepository;
use App\Service\OrganizationHierarchy;
use App\Service\TaskVisibility;
use App\Service\TaskWorkflow;
use App\Support\TaskActivityPresenter;
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

    /**
     * The course plan, scoped to the tasks the user may see: their own, those under a unit they are a
     * superior of, or every task for an admin (see {@see TaskVisibility}). The page itself is open to
     * any authenticated user; the organisation chart decides what shows up, not the permission matrix.
     */
    #[Route('/tareas', name: 'task_index', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] User $user, TaskRepository $tasks, TaskVisibility $visibility): Response
    {
        $schoolYear = $request->query->getString('curso') ?: SchoolYear::current(new \DateTimeImmutable());

        return $this->render('task/index.html.twig', [
            'schoolYear' => $schoolYear,
            'tasks' => $visibility->visibleTo($tasks->findBySchoolYear($schoolYear), $user, $this->isGranted('ROLE_ADMIN')),
        ]);
    }

    /**
     * Creates a task. Each user may assign it to themselves or to someone below them in the chain of
     * command (the scope from {@see OrganizationHierarchy::assignableUnits()}); the choices are
     * limited to that set and re-checked on submit.
     */
    #[Route('/tareas/nueva', name: 'task_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $user, OrganizationHierarchy $hierarchy, RoleRepository $roles, EntityManagerInterface $entityManager): Response
    {
        $units = $this->assignableDepartments($user, $hierarchy);
        $roleChoices = $roles->findAllOrdered();

        $data = new TaskFormData();
        $form = $this->createForm(TaskFormType::class, $data, [
            'assignable_roles' => $roleChoices,
            'assignable_units' => $units,
            'include_deliverable' => true,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->assertResponsibilityAllowed($data, $units);

            // The deliverable toggle also picks the lifecycle: a deliverable task carries the
            // progress/submission/validation flow; a plain one is the simple do-and-validate lifecycle.
            $type = $data->requiresDocument ? TaskType::WITH_DELIVERABLE : TaskType::SIMPLE;
            $task = new Task($data->title, SchoolYear::current($data->dueDate), $data->dueDate, $type);
            $this->applyFormData($task, $data);
            $task->setCreatedBy($user);
            $entityManager->persist($task);
            $entityManager->flush();
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
        $roleChoices = $roles->findAllOrdered();

        $data = TaskFormData::fromTask($task);
        // The deliverable toggle is not shown on edit: the lifecycle is fixed once the task is running.
        $form = $this->createForm(TaskFormType::class, $data, [
            'assignable_roles' => $roleChoices,
            'assignable_units' => $units,
            'include_deliverable' => false,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->assertResponsibilityAllowed($data, $units);

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
        $canWork = $this->canWorkOn($task, $user, $hierarchy);

        // Delegation is a superior's operational call: hand the task to a subordinate they command.
        $canDelegate = $isAdmin || $hierarchy->isSuperiorOf($user, $task->getUnit());
        $delegatable = $canDelegate
            ? array_values(array_filter($this->assignableUsers($user, $hierarchy, $users), static fn (User $u): bool => $u !== $user))
            : [];

        return $this->render('task/show.html.twig', [
            'task' => $task,
            'canWork' => $canWork,
            // Editing/deleting is a management action (creator/superior/admin), a different set than
            // "who works on it" — the template gates the Edit link with this.
            'canManage' => $this->canManage($task, $user, $hierarchy),
            // The lifecycle actions this user may fire now: the workflow's guards already hide the
            // superior-only ones for non-superiors; here we also hide progress ones from outsiders.
            'actions' => $this->availableActions($workflows, $task, $canWork),
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
     * responsibility). A superior's operational action: allowed to a superior of the task's unit or an
     * admin, and only onto someone they actually command.
     */
    #[Route('/tareas/{id}/delegar', name: 'task_delegate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delegate(Task $task, Request $request, #[CurrentUser] User $user, OrganizationHierarchy $hierarchy, UserRepository $users, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('task_delegate'.$task->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        if (!$this->isGranted('ROLE_ADMIN') && !$hierarchy->isSuperiorOf($user, $task->getUnit())) {
            throw $this->createAccessDeniedException('Solo un superior de la unidad puede delegar esta tarea.');
        }

        $delegateeId = (string) $request->request->get('delegatedTo');
        if ('' === $delegateeId) {
            // Recall: back to the structural responsibility.
            $task->setDelegatedTo(null);
        } else {
            $delegatee = $users->find((int) $delegateeId);
            if (null === $delegatee || $delegatee === $user || !\in_array($delegatee, $this->assignableUsers($user, $hierarchy, $users), true)) {
                throw $this->createAccessDeniedException('No puedes delegar en esa persona.');
            }
            $task->setDelegatedTo($delegatee);
        }
        $entityManager->flush();
        $this->addFlash('success', 'Delegación actualizada.');

        return $this->redirectToRoute('task_show', ['id' => $task->getId()]);
    }

    /**
     * Fires a lifecycle transition (empezar/entregar/validar/devolver…) chosen from the task detail.
     * Progress transitions require the assignee; validate/reject are gated by the workflow guard.
     */
    #[Route('/tareas/{id}/accion/{transition}', name: 'task_transition', requirements: ['id' => '\d+', 'transition' => '[a-z_]+'], methods: ['POST'])]
    public function transition(Task $task, string $transition, Request $request, #[CurrentUser] User $user, TaskWorkflow $workflows, OrganizationHierarchy $hierarchy, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('task_transition'.$task->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        if (!\in_array($transition, self::SUPERIOR_TRANSITIONS, true) && !$this->canWorkOn($task, $user, $hierarchy)) {
            throw $this->createAccessDeniedException('Esta tarea no es tuya.');
        }

        $workflow = $workflows->for($task);
        if (!$workflow->can($task, $transition)) {
            // Not enabled from the current state, or blocked by the guard (e.g. non-superior validating).
            throw $this->createAccessDeniedException('Acción no disponible para esta tarea.');
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
    public function setDeliverable(Task $task, Request $request, #[CurrentUser] User $user, OrganizationHierarchy $hierarchy, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('task_deliverable'.$task->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        if (!$this->canWorkOn($task, $user, $hierarchy)) {
            throw $this->createAccessDeniedException('Esta tarea no es tuya.');
        }
        if (!$task->requiresDocument()) {
            throw $this->createNotFoundException('Esta tarea no lleva entregable.');
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
     * The lifecycle transitions to offer as buttons: those enabled now, keeping the superior-only
     * ones (validate/reject) and, for whoever works on the task, the progress ones too.
     *
     * @return list<string> the transition names to show
     */
    private function availableActions(TaskWorkflow $workflows, Task $task, bool $canWork): array
    {
        $names = array_map(
            static fn ($transition): string => $transition->getName(),
            $workflows->for($task)->getEnabledTransitions($task),
        );

        return array_values(array_filter(
            $names,
            fn (string $name): bool => $canWork || \in_array($name, self::SUPERIOR_TRANSITIONS, true),
        ));
    }

    /**
     * One-click "done" from the agenda: toggles the assignee's progress checkbox (distinct from the
     * superior's validation, which is a workflow transition). Only the assignee, a holder of the
     * task's role, or an admin may do it.
     */
    #[Route('/tareas/{id}/hecho', name: 'task_toggle_done', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggleDone(Task $task, Request $request, #[CurrentUser] User $user, OrganizationHierarchy $hierarchy, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('toggle_done'.$task->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        if (!$this->canWorkOn($task, $user, $hierarchy)) {
            throw $this->createAccessDeniedException('Esta tarea no es tuya.');
        }
        if (!$task->requiresCheckbox()) {
            // This task does not close via the progress checkbox (e.g. it is validated by deliverable).
            throw $this->createNotFoundException('Esta tarea no usa casilla de progreso.');
        }

        $task->setCheckboxDone(!$task->isCheckboxDone());
        $entityManager->flush();

        // Back to the agenda, landing on the task just ticked. Route-based (no referer) to avoid
        // any open-redirect; _fragment adds the "#tarea-id" anchor.
        return $this->redirectToRoute('personal_event_index', ['_fragment' => 'tarea-'.$task->getId()]);
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

        // Responsibility = role + (department, only for per-department roles). The department is also
        // the task's unit context for hierarchy/escalation. assignedUser is kept as a mirror of the
        // current holder for the legacy queries still reading it during the transition.
        $role = $data->responsibilityRole;
        $unit = $role->isPerDepartment() ? $data->responsibilityUnit : null;
        $task->setResponsibility(new TaskResponsibility($role, $unit))->setUnit($unit);
        $task->setAssignedUser($task->resolveResponsible());
    }

    /**
     * Guards that a per-department responsibility targets a department the creator may use, re-checked
     * server-side on top of the form's own choice list.
     *
     * @param list<Unit> $assignableUnits the departments the creator may target
     */
    private function assertResponsibilityAllowed(TaskFormData $data, array $assignableUnits): void
    {
        if (null !== $data->responsibilityRole
            && $data->responsibilityRole->isPerDepartment()
            && !\in_array($data->responsibilityUnit, $assignableUnits, true)) {
            throw $this->createAccessDeniedException('No puedes asignar la tarea a ese departamento.');
        }
    }

    /**
     * The departments a user may target as a task's responsibility: those they command (superior of)
     * plus their own, so a member can still set a task for a role within their own department.
     *
     * @return list<Unit> the assignable departments
     */
    private function assignableDepartments(User $user, OrganizationHierarchy $hierarchy): array
    {
        $units = $hierarchy->assignableUnits($user);
        $own = $user->getUnit();
        if (null !== $own && !\in_array($own, $units, true)) {
            $units[] = $own;
        }

        return $units;
    }

    /**
     * The people a user may assign tasks to: everyone in their unit's subtree, plus themselves.
     *
     * @return list<User> the assignable users
     */
    private function assignableUsers(User $user, OrganizationHierarchy $hierarchy, UserRepository $users): array
    {
        $list = $users->findActiveInUnits($hierarchy->assignableUnits($user));
        if (!\in_array($user, $list, true)) {
            $list[] = $user;
        }

        return $list;
    }

    /**
     * Whether the user may edit/delete the task: its creator, a superior of its unit, or an admin.
     */
    private function canManage(Task $task, User $user, OrganizationHierarchy $hierarchy): bool
    {
        return $this->isGranted('ROLE_ADMIN') || $task->getCreatedBy() === $user || $hierarchy->isSuperiorOf($user, $task->getUnit());
    }

    /**
     * Whether the user may act on the task: it is theirs (their person or one of their roles'), they
     * are a superior of its unit in the chain of command, or they are an admin. Superiors can step in
     * and do a subordinate's task (e.g. a task on a "teacher" role that the head of department ends
     * up doing), which the org chart already lets them see and validate.
     */
    private function canWorkOn(Task $task, User $user, OrganizationHierarchy $hierarchy): bool
    {
        return $this->isGranted('ROLE_ADMIN') || $task->isOwnedBy($user) || $hierarchy->isSuperiorOf($user, $task->getUnit());
    }
}
