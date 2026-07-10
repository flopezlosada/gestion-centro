<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\TaskRepository;
use App\Util\SchoolYear;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The home dashboard: the user's personal agenda (their tasks) alongside the centre's reference plan
 * for the current course.
 */
final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_homepage', methods: ['GET'])]
    public function index(#[CurrentUser] User $user, TaskRepository $tasks): Response
    {
        $schoolYear = SchoolYear::current(new \DateTimeImmutable());

        return $this->render('home/index.html.twig', [
            'schoolYear' => $schoolYear,
            'agenda' => $tasks->findAgendaFor($user, $schoolYear),
            'plan' => $tasks->findBySchoolYear($schoolYear),
        ]);
    }
}
