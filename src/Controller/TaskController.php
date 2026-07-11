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
    ): Response {
        $this->denyAccessUnlessGranted(AreaVoter::READ, Area::TASK);

        // The activity history is recorded for everyone but only superiors (up the chain) and admins
        // may read it on the object's page.
        $canSeeHistory = $this->isGranted('ROLE_ADMIN') || $hierarchy->isSuperiorOf($user, $task->getUnit());

        return $this->render('task/show.html.twig', [
            'task' => $task,
            'canSeeHistory' => $canSeeHistory,
            'history' => $canSeeHistory ? $auditLog->findForSubject('Task', (string) $task->getId()) : [],
        ]);
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
