<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Task;
use App\Entity\User;
use App\Form\TaskFormData;
use App\Form\TaskFormType;
use App\Repository\AuditLogRepository;
use App\Repository\TaskRepository;
use App\Repository\UserRepository;
use App\Service\OrganizationHierarchy;
use App\Service\TaskWorkflow;
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
     * The centre task plan for a course. Access is universal but the list is filtered by the chain of
     * command ({@see OrganizationHierarchy::canSeeTask()}): a teacher sees their own tasks, a head of
     * department their department's, and Direction/admins every task.
     */
    #[Route('/tareas', name: 'task_index', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] User $user, TaskRepository $tasks, OrganizationHierarchy $hierarchy): Response
    {
        $schoolYear = $request->query->getString('curso') ?: SchoolYear::current(new \DateTimeImmutable());

        $visible = array_values(array_filter(
            $tasks->findBySchoolYear($schoolYear),
            static fn (Task $task): bool => $hierarchy->canSeeTask($user, $task),
        ));

        return $this->render('task/index.html.twig', [
            'schoolYear' => $schoolYear,
            'tasks' => $visible,
        ]);
    }

    /**
     * Creates a task. Each user may assign it to themselves or to someone below them in the chain of
     * command (the scope from {@see OrganizationHierarchy::assignableUnits()}); the choices are
     * limited to that set and re-checked on submit.
     */
    #[Route('/tareas/nueva', name: 'task_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $user, OrganizationHierarchy $hierarchy, UserRepository $users, EntityManagerInterface $entityManager): Response
    {
        $assignable = $this->assignableUsers($user, $hierarchy, $users);

        $data = new TaskFormData();
        $data->assignedUser = $user;
        $form = $this->createForm(TaskFormType::class, $data, ['assignable_users' => $assignable, 'include_type' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!\in_array($data->assignedUser, $assignable, true)) {
                throw $this->createAccessDeniedException('No puedes asignar tareas a esa persona.');
            }

            $task = new Task($data->title, SchoolYear::current($data->dueDate), $data->dueDate, $data->type);
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
    public function edit(Task $task, Request $request, #[CurrentUser] User $user, OrganizationHierarchy $hierarchy, UserRepository $users, EntityManagerInterface $entityManager): Response
    {
        if (!$this->canManage($task, $user, $hierarchy)) {
            throw $this->createAccessDeniedException('No puedes editar esta tarea.');
        }

        $assignable = $this->assignableUsers($user, $hierarchy, $users);
        // Keep the current assignee as a valid choice even if now outside the scope.
        if (null !== $task->getAssignedUser() && !\in_array($task->getAssignedUser(), $assignable, true)) {
            $assignable[] = $task->getAssignedUser();
        }

        $data = TaskFormData::fromTask($task);
        $form = $this->createForm(TaskFormType::class, $data, ['assignable_users' => $assignable, 'include_type' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!\in_array($data->assignedUser, $assignable, true)) {
                throw $this->createAccessDeniedException('No puedes asignar tareas a esa persona.');
            }

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
        OrganizationHierarchy $hierarchy,
        TaskWorkflow $workflows,
    ): Response {
        // The activity history is recorded for everyone; on the object's page it is shown to the
        // people involved in the task (whoever works on it) and to its superiors/admins — never to
        // an unrelated user.
        $canWork = $this->canWorkOn($task, $user);
        $canSeeHistory = $canWork || $hierarchy->isSuperiorOf($user, $task->getUnit());

        return $this->render('task/show.html.twig', [
            'task' => $task,
            'canWork' => $canWork,
            // The lifecycle actions this user may fire now: the workflow's guards already hide the
            // superior-only ones for non-superiors; here we also hide progress ones from outsiders.
            'actions' => $this->availableActions($workflows, $task, $canWork),
            'canSeeHistory' => $canSeeHistory,
            'history' => $canSeeHistory ? $auditLog->findForSubject('Task', (string) $task->getId()) : [],
        ]);
    }

    /**
     * Fires a lifecycle transition (empezar/entregar/validar/devolver…) chosen from the task detail.
     * Progress transitions require the assignee; validate/reject are gated by the workflow guard.
     */
    #[Route('/tareas/{id}/accion/{transition}', name: 'task_transition', requirements: ['id' => '\d+', 'transition' => '[a-z_]+'], methods: ['POST'])]
    public function transition(Task $task, string $transition, Request $request, #[CurrentUser] User $user, TaskWorkflow $workflows, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('task_transition'.$task->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        if (!\in_array($transition, self::SUPERIOR_TRANSITIONS, true) && !$this->canWorkOn($task, $user)) {
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
    public function toggleDone(Task $task, Request $request, #[CurrentUser] User $user, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('toggle_done'.$task->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        if (!$this->canWorkOn($task, $user)) {
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
        return $this->redirectToRoute('app_homepage', ['_fragment' => 'tarea-'.$task->getId()]);
    }

    /**
     * Copies the common editable fields from the form data onto the task (title, dates, flags,
     * assignee and its unit). Does NOT touch the type — that governs the lifecycle.
     */
    private function applyFormData(Task $task, TaskFormData $data): void
    {
        \assert(null !== $data->dueDate && null !== $data->assignedUser);
        $task->setTitle($data->title)
            ->setDescription($data->description)
            ->setDueDate($data->dueDate)
            ->setSchoolYear(SchoolYear::current($data->dueDate))
            ->setMandatory($data->mandatory)
            ->setRequiresCheckbox($data->requiresCheckbox)
            ->setRequiresDocument($data->requiresDocument)
            ->setAssignedUser($data->assignedUser)
            ->setUnit($data->assignedUser->getUnit());
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
     * Whether the user may act on the task: it is theirs, one of their roles', or they are an admin.
     */
    private function canWorkOn(Task $task, User $user): bool
    {
        if ($this->isGranted('ROLE_ADMIN') || $task->getAssignedUser() === $user) {
            return true;
        }

        $role = $task->getAssignedRole();

        return null !== $role && $user->holdsRole($role);
    }
}
