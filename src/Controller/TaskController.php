<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Task;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use App\Repository\TaskRepository;
use App\Service\OrganizationHierarchy;
use App\Util\SchoolYear;
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
        // The activity history is recorded for everyone but only superiors (up the chain) and admins
        // may read it on the object's page.
        $canSeeHistory = $this->isGranted('ROLE_ADMIN') || $hierarchy->isSuperiorOf($user, $task->getUnit());

        return $this->render('task/show.html.twig', [
            'task' => $task,
            'canSeeHistory' => $canSeeHistory,
            'history' => $canSeeHistory ? $auditLog->findForSubject('Task', (string) $task->getId()) : [],
        ]);
    }
}
