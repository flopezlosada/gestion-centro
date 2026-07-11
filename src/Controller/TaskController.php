<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Task;
use App\Entity\User;
use App\Enum\Area;
use App\Repository\AuditLogRepository;
use App\Repository\TaskRepository;
use App\Security\Voter\AreaVoter;
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

    #[Route('/tareas', name: 'task_index', methods: ['GET'])]
    public function index(Request $request, TaskRepository $tasks): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::READ, Area::TASK);

        $schoolYear = $request->query->getString('curso') ?: SchoolYear::current(new \DateTimeImmutable());

        return $this->render('task/index.html.twig', [
            'schoolYear' => $schoolYear,
            'tasks' => $tasks->findBySchoolYear($schoolYear),
        ]);
    }

    #[Route('/tareas/{id}', name: 'task_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(
        Task $task,
        #[CurrentUser] User $user,
        AuditLogRepository $auditLog,
        OrganizationHierarchy $hierarchy,
        TaskWorkflow $workflows,
    ): Response {
        $this->denyAccessUnlessGranted(AreaVoter::READ, Area::TASK);

        // The activity history is recorded for everyone but only superiors (up the chain) and admins
        // may read it on the object's page.
        $canSeeHistory = $this->isGranted('ROLE_ADMIN') || $hierarchy->isSuperiorOf($user, $task->getUnit());
        $canWork = $this->canWorkOn($task, $user);

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
