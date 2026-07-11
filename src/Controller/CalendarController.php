<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Task;
use App\Entity\User;
use App\Repository\TaskRepository;
use App\Service\OrganizationHierarchy;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Monthly calendar view of the course task plan: the same tasks as the list, laid out on a
 * seven-column (Mon–Sun) month grid by their deadline, with previous/next month navigation.
 */
final class CalendarController extends AbstractController
{
    /** Application time zone: the school lives in peninsular Spain, so "this month" is Madrid's. */
    private const string TIME_ZONE = 'Europe/Madrid';

    /** Spanish month names, indexed 1–12, for the calendar header. */
    private const array MONTH_NAMES = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    /**
     * Renders the month grid for the requested month (or the current one), with the tasks whose
     * deadline falls on each visible day.
     *
     * @param Request              $request   the HTTP request; optional query param "mes" in "YYYY-MM" form
     * @param User                 $user      the current user, to filter the tasks by the chain of command
     * @param TaskRepository       $tasks     the task repository
     * @param OrganizationHierarchy $hierarchy the chain-of-command helper used to filter visible tasks
     *
     * @return Response the rendered calendar page
     */
    #[Route('/calendario', name: 'calendar_index', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] User $user, TaskRepository $tasks, OrganizationHierarchy $hierarchy): Response
    {
        $timeZone = new \DateTimeZone(self::TIME_ZONE);
        $today = new \DateTimeImmutable('today', $timeZone);
        $monthStart = $this->resolveMonthStart($request->query->getString('mes'), $today, $timeZone);
        $monthEnd = $monthStart->modify('last day of this month');

        // Extend the range to the full visible grid (from the Monday of the first week to the Sunday
        // of the last one) so tasks landing on spill-over days of adjacent months also show.
        $gridStart = $monthStart->modify('-'.((int) $monthStart->format('N') - 1).' days');
        $gridEnd = $monthEnd->modify('+'.(7 - (int) $monthEnd->format('N')).' days');

        // Same universal-access-with-hierarchy-filter as the task list: only the tasks this user may see.
        $visible = array_filter(
            $tasks->findDueBetween($gridStart, $gridEnd),
            static fn (Task $task): bool => $hierarchy->canSeeTask($user, $task),
        );

        return $this->render('calendar/index.html.twig', [
            'monthLabel' => self::MONTH_NAMES[(int) $monthStart->format('n')].' '.$monthStart->format('Y'),
            'prevMonth' => $monthStart->modify('-1 month')->format('Y-m'),
            'nextMonth' => $monthStart->modify('+1 month')->format('Y-m'),
            'weeks' => $this->buildWeeks($monthStart, $gridStart, $gridEnd, $today, $visible),
        ]);
    }

    /**
     * Parses the "mes" query parameter into the first day of that month, falling back to the first
     * day of the current month when it is missing or malformed.
     *
     * @param string             $raw      the raw "mes" value, expected in "YYYY-MM" form
     * @param \DateTimeImmutable  $today    the reference "today" used for the fallback
     * @param \DateTimeZone       $timeZone the application time zone
     *
     * @return \DateTimeImmutable midnight on the first day of the resolved month
     */
    private function resolveMonthStart(string $raw, \DateTimeImmutable $today, \DateTimeZone $timeZone): \DateTimeImmutable
    {
        if (1 === preg_match('/^\d{4}-\d{2}$/', $raw)) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw.'-01', $timeZone);
            if (false !== $parsed) {
                return $parsed;
            }
        }

        return $today->modify('first day of this month');
    }

    /**
     * Lays the given tasks out on the month grid: one row per week, seven day cells per row.
     *
     * @param \DateTimeImmutable $monthStart the first day of the month being displayed
     * @param \DateTimeImmutable $gridStart  the first (Monday) day of the visible grid
     * @param \DateTimeImmutable $gridEnd    the last (Sunday) day of the visible grid
     * @param \DateTimeImmutable $today      today, to flag the current day
     * @param Task[]             $tasks      the tasks whose deadline falls within the grid range
     *
     * @return list<list<array{date: \DateTimeImmutable, inMonth: bool, isToday: bool, tasks: Task[]}>> the weeks, each a list of seven day cells
     */
    private function buildWeeks(
        \DateTimeImmutable $monthStart,
        \DateTimeImmutable $gridStart,
        \DateTimeImmutable $gridEnd,
        \DateTimeImmutable $today,
        array $tasks,
    ): array {
        $byDay = [];
        foreach ($tasks as $task) {
            $byDay[$task->getDueDate()->format('Y-m-d')][] = $task;
        }

        $currentMonth = $monthStart->format('Y-m');
        $todayKey = $today->format('Y-m-d');

        $weeks = [];
        $cursor = $gridStart;
        while ($cursor <= $gridEnd) {
            $week = [];
            for ($day = 0; $day < 7; ++$day) {
                $key = $cursor->format('Y-m-d');
                $week[] = [
                    'date' => $cursor,
                    'inMonth' => $cursor->format('Y-m') === $currentMonth,
                    'isToday' => $key === $todayKey,
                    'tasks' => $byDay[$key] ?? [],
                ];
                $cursor = $cursor->modify('+1 day');
            }
            $weeks[] = $week;
        }

        return $weeks;
    }
}
