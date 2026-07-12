<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AcademicYear;
use App\Entity\NonLectiveDay;
use App\Entity\PersonalEvent;
use App\Entity\Task;
use App\Entity\User;
use App\Repository\AcademicYearRepository;
use App\Repository\NonLectiveDayRepository;
use App\Repository\PersonalEventRepository;
use App\Repository\TaskRepository;
use App\Service\SchoolCalendar;
use App\Service\TaskVisibility;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Calendar view of the course task plan, laying each task out by its deadline. The same page serves
 * four zoom levels selected with the "vista" query parameter — day (agenda), week, month and year —
 * all anchored on the "fecha" (YYYY-MM-DD) parameter, with previous/next navigation per level.
 *
 * @phpstan-type DayCell array{date: \DateTimeImmutable, inMonth: bool, isToday: bool, isWeekend: bool, nonLective: ?NonLectiveDay, tasks: Task[], events: PersonalEvent[]}
 * @phpstan-type MiniCell array{day: string, date: string, inMonth: bool, isToday: bool, hasTasks: bool, hasEvents: bool, status: ?string, isNonLective: bool}
 */
final class CalendarController extends AbstractController
{
    /** Application time zone: the school lives in peninsular Spain, so "today" is Madrid's. */
    private const string TIME_ZONE = 'Europe/Madrid';

    /** The day, week, month and year views, and the one used when "vista" is missing or unknown. */
    private const array VIEWS = ['dia', 'semana', 'mes', 'anio'];
    private const string DEFAULT_VIEW = 'mes';

    /** Spanish month names, indexed 1–12, for the calendar labels. */
    private const array MONTH_NAMES = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    /** Spanish weekday names, indexed by ISO-8601 day of week (1 = Monday … 7 = Sunday). */
    private const array WEEKDAY_NAMES = [
        1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo',
    ];

    /**
     * Task status priority for the single dot shown per day in the year view, most attention-needing
     * first: a rejected task must be redone, a pending one not started, and so on down to closed.
     */
    private const array STATUS_PRIORITY = ['rejected', 'pending', 'in_progress', 'submitted', 'done', 'validated'];

    /**
     * Renders the calendar at the requested zoom level and anchor date (both optional), with the
     * tasks whose deadline falls on each visible day and the non-teaching days marked.
     *
     * @param Request                 $request        the HTTP request; optional query params "vista" and "fecha"
     * @param User                    $user           the authenticated user, to scope the visible tasks
     * @param TaskRepository          $tasks          the task repository
     * @param TaskVisibility          $visibility     the task visibility scope built from the organisation chart
     * @param NonLectiveDayRepository $nonLectiveDays the non-teaching day repository
     * @param SchoolCalendar          $schoolCalendar the teaching-day calendar, to flag weekends
     *
     * @return Response the rendered calendar page
     */
    #[Route('/calendario', name: 'calendar_index', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] User $user, TaskRepository $tasks, TaskVisibility $visibility, NonLectiveDayRepository $nonLectiveDays, SchoolCalendar $schoolCalendar, AcademicYearRepository $academicYears, PersonalEventRepository $personalEvents): Response
    {
        $timeZone = new \DateTimeZone(self::TIME_ZONE);
        $today = new \DateTimeImmutable('today', $timeZone);
        $view = $this->resolveView($request->query->getString('vista'));
        $anchor = $this->resolveDate($request->query->getString('fecha'), $today, $timeZone);

        [$rangeStart, $rangeEnd] = $this->rangeFor($view, $anchor);
        $visible = $visibility->visibleTo($tasks->findDueBetween($rangeStart, $rangeEnd), $user, $this->isGranted('ROLE_ADMIN'));
        $byDay = $this->groupByDay($visible, static fn (Task $task): string => $task->getDueDate()->format('Y-m-d'));
        // The user's own private events in the same window (scoped by owner in the repository). The
        // range end is a day at midnight, so widen it to the end of that day to catch events with a time.
        $eventsByDay = $this->groupByDay(
            $personalEvents->findForOwnerBetween($user, $rangeStart, $rangeEnd->setTime(23, 59, 59)),
            static fn (PersonalEvent $event): string => $event->getStartAt()->format('Y-m-d'),
        );
        $nonLectiveByDay = $this->indexNonLectiveDays($nonLectiveDays->findBetween($rangeStart, $rangeEnd));

        $model = match ($view) {
            'dia' => $this->dayModel($anchor, $today, $byDay, $eventsByDay, $nonLectiveByDay, $schoolCalendar),
            'semana' => $this->weekModel($anchor, $today, $byDay, $eventsByDay, $nonLectiveByDay, $schoolCalendar),
            'anio' => $this->yearModel($anchor, $today, $byDay, $eventsByDay, $nonLectiveByDay, $schoolCalendar, $academicYears),
            default => $this->monthModel($anchor, $today, $byDay, $eventsByDay, $nonLectiveByDay, $schoolCalendar),
        };

        return $this->render('calendar/index.html.twig', [
            'view' => $view,
            'anchor' => $anchor,
            'views' => self::VIEWS,
            'prevDate' => $this->step($view, $anchor, -1),
            'nextDate' => $this->step($view, $anchor, 1),
            'todayDate' => $today->format('Y-m-d'),
            ...$model,
        ]);
    }

    /**
     * Normalises the "vista" parameter to one of the known views, falling back to the month view.
     *
     * @param string $raw the raw "vista" value
     *
     * @return string one of {@see self::VIEWS}
     */
    private function resolveView(string $raw): string
    {
        return \in_array($raw, self::VIEWS, true) ? $raw : self::DEFAULT_VIEW;
    }

    /**
     * Parses the "fecha" parameter into a day, falling back to today when it is missing or malformed.
     *
     * @param string             $raw      the raw "fecha" value, expected in "YYYY-MM-DD" form
     * @param \DateTimeImmutable $today    the reference "today" used for the fallback
     * @param \DateTimeZone      $timeZone the application time zone
     *
     * @return \DateTimeImmutable midnight on the resolved day
     */
    private function resolveDate(string $raw, \DateTimeImmutable $today, \DateTimeZone $timeZone): \DateTimeImmutable
    {
        // Validate the calendar date, not just its shape: checkdate() rejects overflow days
        // (e.g. 2026-02-30) that createFromFormat() would silently roll over into the next month.
        if (1 === preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $parts) && \checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $timeZone);
            if (false !== $parsed) {
                return $parsed;
            }
        }

        return $today;
    }

    /**
     * The inclusive day range whose tasks the given view needs: the day itself, its Monday–Sunday
     * week, the full visible month grid (including spill-over days), or the whole calendar year.
     *
     * @param string             $view   the resolved view
     * @param \DateTimeImmutable $anchor the anchor day
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} the range start and end
     */
    private function rangeFor(string $view, \DateTimeImmutable $anchor): array
    {
        if ('semana' === $view) {
            $start = $this->weekStart($anchor);

            return [$start, $start->modify('+6 days')];
        }

        $year = (int) $anchor->format('Y');

        if ('anio' === $view) {
            // The year view shows the SCHOOL year (September→August), not the calendar year, so its
            // three terms read left to right.
            $start = $this->schoolYearStart($anchor);

            return [$start, $start->modify('+1 year')->modify('-1 day')];
        }

        return match ($view) {
            'dia' => [$anchor, $anchor],
            default => $this->monthGridRange($anchor),
        };
    }

    /**
     * The first day (1 September) of the school year the anchor falls in. From September on the anchor
     * is in the year that starts that calendar year; before September, in the one that started the
     * previous year.
     *
     * @param \DateTimeImmutable $anchor the anchor day
     *
     * @return \DateTimeImmutable 1 September of the school year's starting calendar year
     */
    private function schoolYearStart(\DateTimeImmutable $anchor): \DateTimeImmutable
    {
        $year = (int) $anchor->format('Y');
        $startYear = (int) $anchor->format('n') >= 9 ? $year : $year - 1;

        return $anchor->setDate($startYear, 9, 1);
    }

    /**
     * The anchor date shifted one step in the given direction at the granularity of the view: a day,
     * a week, a month or a year. Used to build the previous/next navigation links.
     *
     * @param string             $view   the resolved view
     * @param \DateTimeImmutable $anchor the current anchor day
     * @param int                $dir    the direction, -1 for previous or +1 for next
     *
     * @return string the shifted day in "YYYY-MM-DD" form
     */
    private function step(string $view, \DateTimeImmutable $anchor, int $dir): string
    {
        $shifted = match ($view) {
            'dia' => $anchor->modify(\sprintf('%+d days', $dir)),
            'semana' => $anchor->modify(\sprintf('%+d days', 7 * $dir)),
            // Keep the same month/day so switching Year→Month after the jump lands on the right month;
            // setDate normalises a 29-Feb anchor into a non-leap target year natively.
            'anio' => $anchor->setDate((int) $anchor->format('Y') + $dir, (int) $anchor->format('n'), (int) $anchor->format('j')),
            default => $anchor->modify('first day of this month')->modify(\sprintf('%+d month', $dir)),
        };

        return $shifted->format('Y-m-d');
    }

    /**
     * Groups the given items by day (keyed "YYYY-MM-DD"), where each item's day is read by $dayOf.
     * Shared by tasks (by deadline) and personal events (by start), so the two never drift apart.
     *
     * @template T
     *
     * @param T[]                 $items the items to group
     * @param callable(T): string $dayOf reads an item's "YYYY-MM-DD" day
     *
     * @return array<string, T[]> the items indexed by day
     */
    private function groupByDay(array $items, callable $dayOf): array
    {
        $byDay = [];
        foreach ($items as $item) {
            $byDay[$dayOf($item)][] = $item;
        }

        return $byDay;
    }

    /**
     * Indexes the given non-teaching days by day, keyed "YYYY-MM-DD".
     *
     * @param NonLectiveDay[] $days the non-teaching days to index
     *
     * @return array<string, NonLectiveDay> the non-teaching days indexed by day
     */
    private function indexNonLectiveDays(array $days): array
    {
        $byDay = [];
        foreach ($days as $day) {
            $byDay[$day->getDate()->format('Y-m-d')] = $day;
        }

        return $byDay;
    }

    /**
     * The day-view model: the single anchor day as a cell, with the tasks due on it.
     *
     * @param \DateTimeImmutable                 $anchor          the anchor day
     * @param \DateTimeImmutable                 $today           today, to flag the current day
     * @param array<string, Task[]>              $byDay           tasks indexed by deadline day
     * @param array<string, PersonalEvent[]>     $eventsByDay     personal events indexed by start day
     * @param array<string, NonLectiveDay>       $nonLectiveByDay non-teaching days indexed by day
     * @param SchoolCalendar                     $schoolCalendar  the teaching-day calendar
     *
     * @return array{template: string, label: string, day: DayCell} the template and view data
     */
    private function dayModel(\DateTimeImmutable $anchor, \DateTimeImmutable $today, array $byDay, array $eventsByDay, array $nonLectiveByDay, SchoolCalendar $schoolCalendar): array
    {
        return [
            'template' => 'calendar/_day.html.twig',
            'label' => self::WEEKDAY_NAMES[(int) $anchor->format('N')].', '.$anchor->format('j').' de '.$this->monthName($anchor).' de '.$anchor->format('Y'),
            'day' => $this->cell($anchor, null, $today, $byDay, $eventsByDay, $nonLectiveByDay, $schoolCalendar),
        ];
    }

    /**
     * The week-view model: the Monday–Sunday week containing the anchor day, as seven cells.
     *
     * @param \DateTimeImmutable                 $anchor          the anchor day
     * @param \DateTimeImmutable                 $today           today, to flag the current day
     * @param array<string, Task[]>              $byDay           tasks indexed by deadline day
     * @param array<string, PersonalEvent[]>     $eventsByDay     personal events indexed by start day
     * @param array<string, NonLectiveDay>       $nonLectiveByDay non-teaching days indexed by day
     * @param SchoolCalendar                     $schoolCalendar  the teaching-day calendar
     *
     * @return array{template: string, label: string, week: list<DayCell>} the template and view data
     */
    private function weekModel(\DateTimeImmutable $anchor, \DateTimeImmutable $today, array $byDay, array $eventsByDay, array $nonLectiveByDay, SchoolCalendar $schoolCalendar): array
    {
        $start = $this->weekStart($anchor);
        $end = $start->modify('+6 days');
        $week = [];
        for ($day = 0; $day < 7; ++$day) {
            $week[] = $this->cell($start->modify('+'.$day.' days'), null, $today, $byDay, $eventsByDay, $nonLectiveByDay, $schoolCalendar);
        }

        return [
            'template' => 'calendar/_week.html.twig',
            'label' => $this->rangeLabel($start, $end),
            'week' => $week,
        ];
    }

    /**
     * The month-view model: the visible month grid as one row of seven cells per week.
     *
     * @param \DateTimeImmutable                 $anchor          the anchor day (its month is displayed)
     * @param \DateTimeImmutable                 $today           today, to flag the current day
     * @param array<string, Task[]>              $byDay           tasks indexed by deadline day
     * @param array<string, PersonalEvent[]>     $eventsByDay     personal events indexed by start day
     * @param array<string, NonLectiveDay>       $nonLectiveByDay non-teaching days indexed by day
     * @param SchoolCalendar                     $schoolCalendar  the teaching-day calendar
     *
     * @return array{template: string, label: string, weeks: list<list<DayCell>>} the template and view data
     */
    private function monthModel(\DateTimeImmutable $anchor, \DateTimeImmutable $today, array $byDay, array $eventsByDay, array $nonLectiveByDay, SchoolCalendar $schoolCalendar): array
    {
        return [
            'template' => 'calendar/_month.html.twig',
            'label' => $this->monthName($anchor).' '.$anchor->format('Y'),
            'weeks' => $this->monthWeeks($anchor, $today, $byDay, $eventsByDay, $nonLectiveByDay, $schoolCalendar),
        ];
    }

    /**
     * The year-view model: the twelve months of the SCHOOL year (September→August), each as a compact
     * grid whose days carry a single status dot when a task is due and a muted style when non-teaching.
     * Each month is tagged with the term (1–3) it belongs to, when the school year's structure is
     * defined, so the view can colour the three terms.
     *
     * @param \DateTimeImmutable                 $anchor          the anchor day (its school year is displayed)
     * @param \DateTimeImmutable                 $today           today, to flag the current day
     * @param array<string, Task[]>              $byDay           tasks indexed by deadline day
     * @param array<string, PersonalEvent[]>     $eventsByDay     personal events indexed by start day
     * @param array<string, NonLectiveDay>       $nonLectiveByDay non-teaching days indexed by day
     * @param SchoolCalendar                     $schoolCalendar  the teaching-day calendar
     * @param AcademicYearRepository             $academicYears   the school-year structure repository
     *
     * @return array{template: string, label: string, months: list<array{name: string, date: string, term: ?int, weeks: list<list<MiniCell>>}>} the template and view data
     */
    private function yearModel(\DateTimeImmutable $anchor, \DateTimeImmutable $today, array $byDay, array $eventsByDay, array $nonLectiveByDay, SchoolCalendar $schoolCalendar, AcademicYearRepository $academicYears): array
    {
        $start = $this->schoolYearStart($anchor);
        $startYear = (int) $start->format('Y');
        $schoolYear = \sprintf('%d-%d', $startYear, $startYear + 1);
        $structure = $academicYears->findBySchoolYear($schoolYear);

        $months = [];
        for ($i = 0; $i < 12; ++$i) {
            $first = $start->modify(\sprintf('+%d months', $i));
            $weeks = [];
            foreach ($this->monthWeeks($first, $today, $byDay, $eventsByDay, $nonLectiveByDay, $schoolCalendar) as $week) {
                $weeks[] = array_map($this->miniCell(...), $week);
            }
            $months[] = [
                'name' => self::MONTH_NAMES[(int) $first->format('n')],
                'date' => $first->format('Y-m-d'),
                'term' => null !== $structure ? $this->termForMonth($structure, $first) : null,
                'weeks' => $weeks,
            ];
        }

        return [
            'template' => 'calendar/_year.html.twig',
            'label' => $schoolYear,
            'months' => $months,
        ];
    }

    /**
     * The term (1–3) a month belongs to, judged by its middle day, or null when that falls in a break
     * or the summer (outside every term).
     *
     * @param AcademicYear       $structure the school year's term structure
     * @param \DateTimeImmutable $first     the first day of the month
     *
     * @return int|null the term number, or null if the month sits outside the terms
     */
    private function termForMonth(AcademicYear $structure, \DateTimeImmutable $first): ?int
    {
        $mid = $first->modify('+14 days');
        foreach ([1, 2, 3] as $term) {
            if ($mid >= $structure->getTermStart($term) && $mid <= $structure->getTermEnd($term)) {
                return $term;
            }
        }

        return null;
    }

    /**
     * Builds the weeks of the visible grid for the month of the given day: from the Monday of its
     * first week to the Sunday of its last, seven cells per week.
     *
     * @param \DateTimeImmutable                 $anchor          the day whose month is laid out
     * @param \DateTimeImmutable                 $today           today, to flag the current day
     * @param array<string, Task[]>              $byDay           tasks indexed by deadline day
     * @param array<string, PersonalEvent[]>     $eventsByDay     personal events indexed by start day
     * @param array<string, NonLectiveDay>       $nonLectiveByDay non-teaching days indexed by day
     * @param SchoolCalendar                     $schoolCalendar  the teaching-day calendar
     *
     * @return list<list<DayCell>> the weeks, each a list of seven day cells
     */
    private function monthWeeks(\DateTimeImmutable $anchor, \DateTimeImmutable $today, array $byDay, array $eventsByDay, array $nonLectiveByDay, SchoolCalendar $schoolCalendar): array
    {
        [$gridStart, $gridEnd] = $this->monthGridRange($anchor);
        $month = $anchor->format('Y-m');

        $weeks = [];
        $cursor = $gridStart;
        while ($cursor <= $gridEnd) {
            $week = [];
            for ($day = 0; $day < 7; ++$day) {
                $week[] = $this->cell($cursor, $month, $today, $byDay, $eventsByDay, $nonLectiveByDay, $schoolCalendar);
                $cursor = $cursor->modify('+1 day');
            }
            $weeks[] = $week;
        }

        return $weeks;
    }

    /**
     * The inclusive range of the visible month grid: the Monday of the month's first week to the
     * Sunday of its last week.
     *
     * @param \DateTimeImmutable $anchor the day whose month grid is measured
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} the grid start and end
     */
    private function monthGridRange(\DateTimeImmutable $anchor): array
    {
        $monthStart = $anchor->modify('first day of this month');
        $monthEnd = $anchor->modify('last day of this month');

        return [
            $monthStart->modify('-'.((int) $monthStart->format('N') - 1).' days'),
            $monthEnd->modify('+'.(7 - (int) $monthEnd->format('N')).' days'),
        ];
    }

    /**
     * The Monday of the ISO week containing the given day.
     *
     * @param \DateTimeImmutable $date the day
     *
     * @return \DateTimeImmutable the Monday of that week
     */
    private function weekStart(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return $date->modify('-'.((int) $date->format('N') - 1).' days');
    }

    /**
     * Builds one day cell. A cell is "in month" when it belongs to $month (or always, for the day and
     * week views where $month is null); only in-month days carry the non-teaching marker, so the
     * month's own non-teaching days stand apart from the neighbouring-month days that spill into the grid.
     *
     * @param \DateTimeImmutable                 $date            the cell's day
     * @param string|null                        $month           the displayed month "YYYY-MM", or null to treat every day as in-month
     * @param \DateTimeImmutable                 $today           today, to flag the current day
     * @param array<string, Task[]>              $byDay           tasks indexed by deadline day
     * @param array<string, PersonalEvent[]>     $eventsByDay     personal events indexed by start day
     * @param array<string, NonLectiveDay>       $nonLectiveByDay non-teaching days indexed by day
     * @param SchoolCalendar                     $schoolCalendar  the teaching-day calendar
     *
     * @return DayCell the cell
     */
    private function cell(\DateTimeImmutable $date, ?string $month, \DateTimeImmutable $today, array $byDay, array $eventsByDay, array $nonLectiveByDay, SchoolCalendar $schoolCalendar): array
    {
        $key = $date->format('Y-m-d');
        $inMonth = null === $month || $date->format('Y-m') === $month;

        return [
            'date' => $date,
            'inMonth' => $inMonth,
            'isToday' => $key === $today->format('Y-m-d'),
            'isWeekend' => $schoolCalendar->isWeekend($date),
            // Neighbouring-month days that spill into the grid are context only: don't mark them non-teaching.
            'nonLective' => $inMonth ? ($nonLectiveByDay[$key] ?? null) : null,
            'tasks' => $byDay[$key] ?? [],
            'events' => $eventsByDay[$key] ?? [],
        ];
    }

    /**
     * Reduces a full day cell to the compact shape the year view needs: the day number, whether it is
     * in its month, today, and the single representative status dot for the tasks due that day.
     *
     * @param DayCell $cell the full day cell built by {@see self::cell()}
     *
     * @return MiniCell the compact cell
     */
    private function miniCell(array $cell): array
    {
        return [
            'day' => $cell['date']->format('j'),
            'date' => $cell['date']->format('Y-m-d'),
            'inMonth' => $cell['inMonth'],
            'isToday' => $cell['isToday'],
            'hasTasks' => $cell['inMonth'] && [] !== $cell['tasks'],
            'hasEvents' => $cell['inMonth'] && [] !== $cell['events'],
            'status' => $cell['inMonth'] ? $this->topStatus($cell['tasks']) : null,
            // Non-teaching: a weekend or a registered holiday. Both are shown muted in the year grid.
            'isNonLective' => $cell['inMonth'] && ($cell['isWeekend'] || null !== $cell['nonLective']),
        ];
    }

    /**
     * The most attention-needing status among the given tasks, per {@see self::STATUS_PRIORITY}, or
     * null when there are none. Drives the colour of the single dot shown per day in the year view.
     *
     * @param Task[] $tasks the tasks due on a day
     *
     * @return string|null the representative status, or null when the day has no tasks
     */
    private function topStatus(array $tasks): ?string
    {
        $best = null;
        $bestRank = \PHP_INT_MAX;
        foreach ($tasks as $task) {
            $rank = array_search($task->getStatus(), self::STATUS_PRIORITY, true);
            if (false !== $rank && $rank < $bestRank) {
                $bestRank = $rank;
                $best = $task->getStatus();
            }
        }

        return $best;
    }

    /**
     * The Spanish name of the given day's month.
     *
     * @param \DateTimeImmutable $date the day
     *
     * @return string the month name
     */
    private function monthName(\DateTimeImmutable $date): string
    {
        return self::MONTH_NAMES[(int) $date->format('n')];
    }

    /**
     * A human label for a day range, collapsing the shared month when both ends share it: e.g.
     * "6 – 12 de julio de 2026", or "29 de junio – 5 de julio de 2026" when the week straddles two months.
     *
     * @param \DateTimeImmutable $start the first day of the range
     * @param \DateTimeImmutable $end   the last day of the range
     *
     * @return string the range label
     */
    private function rangeLabel(\DateTimeImmutable $start, \DateTimeImmutable $end): string
    {
        $sameYear = $start->format('Y') === $end->format('Y');
        $left = match (true) {
            $sameYear && $start->format('m') === $end->format('m') => $start->format('j'),
            $sameYear => $start->format('j').' de '.$this->monthName($start),
            default => $start->format('j').' de '.$this->monthName($start).' de '.$start->format('Y'),
        };

        return $left.' – '.$end->format('j').' de '.$this->monthName($end).' de '.$end->format('Y');
    }
}
