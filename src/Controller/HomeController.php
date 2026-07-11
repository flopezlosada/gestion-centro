<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Task;
use App\Entity\User;
use App\Repository\TaskRepository;
use App\Util\SchoolYear;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The landing page: the user's personal agenda — "what do I have to do" — grouped by time
 * (overdue, today, next 7 days, later), with the done ones set apart. This is the one screen the
 * research says drives daily use; the centre-wide plan lives under Tasks.
 */
final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_homepage', methods: ['GET'])]
    public function index(#[CurrentUser] User $user, TaskRepository $tasks): Response
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Madrid'));
        $agenda = $tasks->findAgendaFor($user, SchoolYear::current($today));

        return $this->render('home/index.html.twig', [
            'firstName' => explode(' ', trim($user->getFullName()))[0],
            'buckets' => $this->groupByTime($agenda, $today),
        ]);
    }

    /**
     * Splits the agenda into time buckets by due date, keeping the ones already ticked "done" apart.
     * Dates are compared as "YYYY-MM-DD" strings so the result is timezone-proof.
     *
     * @param Task[]             $tasks the user's tasks for the course
     * @param \DateTimeImmutable $today the reference day
     *
     * @return array{overdue: Task[], today: Task[], week: Task[], later: Task[], done: Task[]}
     */
    private function groupByTime(array $tasks, \DateTimeImmutable $today): array
    {
        $todayStr = $today->format('Y-m-d');
        $weekStr = $today->modify('+7 days')->format('Y-m-d');
        $buckets = ['overdue' => [], 'today' => [], 'week' => [], 'later' => [], 'done' => []];

        foreach ($tasks as $task) {
            if ($task->isCheckboxDone()) {
                $buckets['done'][] = $task;
                continue;
            }
            $due = $task->getDueDate()->format('Y-m-d');
            $bucket = match (true) {
                $due < $todayStr => 'overdue',
                $due === $todayStr => 'today',
                $due <= $weekStr => 'week',
                default => 'later',
            };
            $buckets[$bucket][] = $task;
        }

        return $buckets;
    }
}
