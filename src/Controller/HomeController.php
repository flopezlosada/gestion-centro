<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Enum\Area;
use App\Home\HomeDashboard;
use App\Security\Voter\AreaVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The site root: the personal "qué me toca hoy" home. It leads with the viewer's next guardia, their
 * tasks due today or overdue, and their private agenda — the at-a-glance landing for TODAY, not the
 * full lists ({@see TaskController::index} for the task backlog, {@see CalendarController::index} for
 * events on any other day). Role-aware modules (mi departamento, el centro, guardias de hoy) grow on
 * top of this base. See {@see HomeDashboard}.
 */
final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_homepage', methods: ['GET'])]
    public function index(#[CurrentUser] User $user, HomeDashboard $dashboard): Response
    {
        $today = new \DateTimeImmutable('today');
        $now = new \DateTimeImmutable('now');

        $isGuardiaCoordinator = $this->isGranted(AreaVoter::WRITE, Area::GUARDIAS);

        return $this->render('home/index.html.twig', $dashboard->baseFor($user, $today, $now) + [
            'now' => $now,
            'modules' => $dashboard->modulesFor($user, $today, $isGuardiaCoordinator),
        ]);
    }
}
