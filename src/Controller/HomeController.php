<?php

declare(strict_types=1);

namespace App\Controller;

use App\Agenda\PersonalAgenda;
use App\Dashboard\CentreDashboard;
use App\Entity\User;
use App\Repository\TaskRepository;
use App\Service\TaskVisibility;
use App\Util\SchoolYear;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The site root: the panel ("cómo van las tareas"). It shows an overview of the course scoped to what
 * the viewer may see — a director gets the whole centre, a plain teacher gets only their own tasks
 * (via {@see TaskVisibility}) — plus a compact glance of their personal agenda. The full task list
 * lives at {@see TaskController::index} and the full agenda at {@see PersonalEventController::index};
 * this is the at-a-glance landing, not either of those.
 */
final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_homepage', methods: ['GET'])]
    public function index(
        Request $request,
        #[CurrentUser] User $user,
        TaskRepository $tasks,
        TaskVisibility $visibility,
        CentreDashboard $dashboard,
        PersonalAgenda $agenda,
    ): Response {
        $today = new \DateTimeImmutable('today');
        $schoolYear = $request->query->getString('curso') ?: SchoolYear::current($today);
        // Cursos con tareas + el actual, para el selector de histórico (se muestra si hay más de uno).
        $years = $tasks->schoolYearsWithTasks();
        if (!\in_array($schoolYear, $years, true)) {
            $years[] = $schoolYear;
            rsort($years);
        }
        $visible = $visibility->visibleTo($tasks->findBySchoolYear($schoolYear), $user, $this->isGranted('ROLE_ADMIN'));
        // La agenda es siempre "ahora" (personal), no se ata al curso histórico seleccionado.
        $buckets = $agenda->bucketsFor($user, $today);

        return $this->render('home/index.html.twig', [
            'schoolYear' => $schoolYear,
            'years' => $years,
            'overview' => $dashboard->overview($visible, $today),
            'agendaToday' => $buckets['today'],
            'agendaWeek' => $buckets['week'],
        ]);
    }
}
