<?php

declare(strict_types=1);

namespace App\Controller;

use App\Agenda\AgendaEntry;
use App\Entity\User;
use App\Repository\PersonalEventRepository;
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
 *
 * The agenda mixes two kinds of entry: the institutional tasks owned by the user and their own
 * private {@see \App\Entity\PersonalEvent}s, wrapped behind {@see AgendaEntry} so they sort and
 * bucket together while each still renders with its own template.
 */
final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_homepage', methods: ['GET'])]
    public function index(#[CurrentUser] User $user, TaskRepository $tasks, PersonalEventRepository $events): Response
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Madrid'));
        $taskAgenda = $tasks->findAgendaFor($user, SchoolYear::current($today));
        // From a month back so a recently-missed personal reminder still shows as overdue; future ones
        // fall into their own buckets. Private to the user — the repository scopes by owner.
        $eventAgenda = $events->findUpcomingFor($user, $today->modify('-1 month'));

        $entries = [
            ...array_map(AgendaEntry::fromTask(...), $taskAgenda),
            ...array_map(AgendaEntry::fromEvent(...), $eventAgenda),
        ];

        return $this->render('home/index.html.twig', [
            'firstName' => explode(' ', trim($user->getFullName()))[0],
            'buckets' => $this->groupByTime($entries, $today),
        ]);
    }

    /**
     * Splits the agenda into time buckets by date, keeping the ones already done apart, and orders
     * each bucket chronologically so tasks and events interleave by day. Dates are compared as
     * "YYYY-MM-DD" strings so the result is timezone-proof.
     *
     * @param AgendaEntry[]      $entries the user's tasks and personal events, wrapped
     * @param \DateTimeImmutable $today   the reference day
     *
     * @return array{overdue: AgendaEntry[], today: AgendaEntry[], week: AgendaEntry[], later: AgendaEntry[], done: AgendaEntry[]}
     */
    private function groupByTime(array $entries, \DateTimeImmutable $today): array
    {
        usort($entries, static fn (AgendaEntry $a, AgendaEntry $b): int => $a->date <=> $b->date);

        $todayStr = $today->format('Y-m-d');
        $weekStr = $today->modify('+7 days')->format('Y-m-d');
        $buckets = ['overdue' => [], 'today' => [], 'week' => [], 'later' => [], 'done' => []];

        foreach ($entries as $entry) {
            if ($entry->done) {
                $buckets['done'][] = $entry;
                continue;
            }
            $day = $entry->date->format('Y-m-d');
            $bucket = match (true) {
                $day < $todayStr => 'overdue',
                $day === $todayStr => 'today',
                $day <= $weekStr => 'week',
                default => 'later',
            };
            $buckets[$bucket][] = $entry;
        }

        return $buckets;
    }
}
