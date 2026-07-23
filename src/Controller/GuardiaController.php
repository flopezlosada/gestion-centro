<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AcademicYear;
use App\Entity\GuardiaCover;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\ScheduleActivityKind;
use App\Enum\Weekday;
use App\Guardia\AbsenceRegistrar;
use App\Guardia\AbsenceRegistrationResult;
use App\Guardia\GuardiaScheduler;
use App\Guardia\GuardiaStatistics;
use App\Repository\AcademicYearRepository;
use App\Repository\AuditLogRepository;
use App\Repository\GuardiaCoverRepository;
use App\Repository\ScheduleEntryRepository;
use App\Repository\UserRepository;
use App\Security\Voter\AreaVoter;
use App\Service\GuardiaAssignmentNotifier;
use App\Support\AuditContext;
use App\Support\GuardiaActivityPresenter;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The daily "Parte de guardias": for a chosen day and period it shows the absences to cover, the
 * guardia teacher assigned to each (filled automatically and overridable), and the pool of guardia
 * teachers on call that period with their accumulated load.
 *
 * Assignment is automatic (the equitable {@see GuardiaScheduler}) with manual override, per the
 * centre's decision. The covers are {@see \App\Contract\Auditable}, so every change is trailed
 * automatically.
 *
 * This is the guardia-coordinator surface, gated by the {@see Area::GUARDIAS} matrix: viewing (parte,
 * history, stats) needs READ, every mutation needs WRITE (ROLE_ADMIN bypasses). Two self-service
 * exceptions are open to any authenticated user and scoped to themselves: {@see mine()} (a teacher's
 * own "mis guardias") and registering an absence ({@see newAbsence()}/{@see createAbsence()}), where a
 * non-coordinator may only report their OWN absence — a coordinator may register anyone.
 */
#[Route('/guardias')]
final class GuardiaController extends AbstractController
{
    /**
     * Shows the parte for a date and period, plus the on-call pool. Date and period come from the
     * query string (today and the first period of the day by default).
     */
    #[Route('', name: 'guardia_index', methods: ['GET'])]
    public function index(Request $request, ScheduleEntryRepository $schedule, GuardiaCoverRepository $covers, AcademicYearRepository $years): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::READ, Area::GUARDIAS);

        $date = $this->dateFromRequest($request);
        $schoolYear = SchoolYear::current($date);
        $year = $years->findBySchoolYear($schoolYear);
        $weekday = Weekday::from((int) $date->format('N'));

        // Slots and the guardia pool come from the timetable of the course this date falls into; with
        // no course imported for it there is nothing to show but the empty state.
        $slots = null !== $year ? $schedule->distinctSlots($year) : [];
        $slotIndex = $this->slotFromRequest($request, $slots);
        $pool = null !== $year ? $schedule->dutyPoolAt($year, $weekday, $slotIndex) : [];
        $parte = $covers->findForParte($date, $slotIndex);

        // The group each on-call teacher is already covering this period, so the pool panel can tell
        // who is busy from who is still free at a glance.
        $assignedHere = [];
        foreach ($parte as $cover) {
            $guardia = $cover->getAssignedGuardia();
            if (null !== $guardia && null !== $guardia->getId()) {
                $assignedHere[$guardia->getId()] = $cover->getGroupName() ?? 'un grupo';
            }
        }

        return $this->render('guardia/index.html.twig', [
            'date' => $date,
            'weekday' => $weekday,
            'schoolYear' => $schoolYear,
            'slots' => $slots,
            'slotIndex' => $slotIndex,
            'covers' => $parte,
            'pool' => $pool,
            'slotLoad' => $covers->loadBySlot($slotIndex),
            'absentIds' => $covers->absentTeacherIdsAt($date, $slotIndex),
            'assignedHere' => $assignedHere,
        ]);
    }

    /**
     * The teacher's own "mis guardias": today's guardias front and centre (period time, group, room,
     * absent teacher and any task left), plus the ones coming up on later days. Shows only their own.
     */
    #[Route('/mias', name: 'guardia_mine', methods: ['GET'])]
    public function mine(#[CurrentUser] User $user, GuardiaCoverRepository $covers, ScheduleEntryRepository $schedule, AcademicYearRepository $years): Response
    {
        $today = new \DateTimeImmutable('today');
        $now = new \DateTimeImmutable('now');
        $year = $years->findBySchoolYear(SchoolYear::current($today));
        $slotTimes = $this->slotTimes($schedule, $year);

        return $this->render('guardia/mine.html.twig', [
            'today' => $this->buildTodayView($covers->findAssignedTo($user, $today), $slotTimes, $now),
            'upcoming' => $this->groupByDay($covers->findUpcomingAssignedTo($user, $today->modify('+1 day')), $today),
            'slotTimes' => $slotTimes,
        ]);
    }

    /**
     * Turns a teacher's covers for today into the "mis guardias de hoy" view model the redesign needs:
     * each cover flagged done/pending against the current time, the countdown to the next one still to
     * cover (the screen's protagonist) and the day's tallies for the summary panel.
     *
     * A cover counts as done only when its period end time is known AND already past; with no imported
     * timetable (unknown times) nothing can be called done, so every cover stays pending.
     *
     * @param GuardiaCover[]                                                   $covers    today's covers, earliest period first
     * @param array<int, array{startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}> $slotTimes times by slot index
     * @param \DateTimeImmutable                                               $now       the current instant
     *
     * @return array{items: list<array{cover: GuardiaCover, done: bool, startsAt: ?\DateTimeImmutable, endsAt: ?\DateTimeImmutable, minutesUntil: ?int}>, next: ?int, counts: array{assigned: int, pending: int, withTask: int}}
     */
    private function buildTodayView(array $covers, array $slotTimes, \DateTimeImmutable $now): array
    {
        $items = [];
        $next = null;
        $pending = 0;
        $withTask = 0;

        foreach ($covers as $i => $cover) {
            $times = $slotTimes[$cover->getSlotIndex()] ?? null;
            $startsAt = $times['startsAt'] ?? null;
            $endsAt = $times['endsAt'] ?? null;
            $done = null !== $endsAt && $endsAt < $now;

            if (!$done) {
                ++$pending;
                $next ??= $i; // the first cover not yet done is the protagonist ("tu próxima guardia")
            }
            if (null !== $cover->getTaskNote()) {
                ++$withTask;
            }

            $items[] = [
                'cover' => $cover,
                'done' => $done,
                'startsAt' => $startsAt,
                'endsAt' => $endsAt,
                'minutesUntil' => null !== $startsAt && $startsAt > $now ? intdiv($startsAt->getTimestamp() - $now->getTimestamp(), 60) : null,
            ];
        }

        return [
            'items' => $items,
            'next' => $next,
            'counts' => ['assigned' => \count($covers), 'pending' => $pending, 'withTask' => $withTask],
        ];
    }

    /**
     * The teacher's own guardia history: the guardias they were assigned before today, most recent
     * first — a plain table to look back at what they covered. Self-service, scoped to themselves.
     */
    #[Route('/mias/historico', name: 'guardia_mine_history', methods: ['GET'])]
    public function mineHistory(#[CurrentUser] User $user, GuardiaCoverRepository $covers, ScheduleEntryRepository $schedule, AcademicYearRepository $years): Response
    {
        $today = new \DateTimeImmutable('today');
        $year = $years->findBySchoolYear(SchoolYear::current($today));

        return $this->render('guardia/mine_history.html.twig', [
            'covers' => $covers->findPastAssignedTo($user, $today),
            'slotTimes' => $this->slotTimes($schedule, $year),
        ]);
    }

    /**
     * Groups a teacher's upcoming covers by day for the "mis guardias" screen, flagging today/tomorrow
     * so the view can label and highlight them.
     *
     * @param list<GuardiaCover> $covers the covers, already ordered by date then period
     * @param \DateTimeImmutable $today  today, to tag the nearest days
     *
     * @return list<array{date: \DateTimeImmutable, isToday: bool, isTomorrow: bool, covers: list<GuardiaCover>}> one entry per day, chronological
     */
    private function groupByDay(array $covers, \DateTimeImmutable $today): array
    {
        $todayKey = $today->format('Y-m-d');
        $tomorrowKey = $today->modify('+1 day')->format('Y-m-d');

        $days = [];
        foreach ($covers as $cover) {
            $key = $cover->getDate()->format('Y-m-d');
            $days[$key] ??= ['date' => $cover->getDate(), 'isToday' => $key === $todayKey, 'isTomorrow' => $key === $tomorrowKey, 'covers' => []];
            $days[$key]['covers'][] = $cover;
        }

        return array_values($days);
    }

    /**
     * The guardia log with optional filters (date range, group, guardia teacher, absent teacher) — the
     * trace of "who covered which group when". Read access to the guardia area is enough to view it.
     */
    #[Route('/historico', name: 'guardia_history', methods: ['GET'])]
    public function history(Request $request, GuardiaCoverRepository $covers, ScheduleEntryRepository $schedule, AcademicYearRepository $years): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::READ, Area::GUARDIAS);

        // Scoped to one course (like "tareas del centro"), so the client-side table tools work over a
        // bounded set instead of the whole multi-year history. Filtering/sorting/search all happen in
        // the browser (see templates/guardia/history.html.twig).
        $curso = (string) ($request->query->get('curso') ?: SchoolYear::current(new \DateTimeImmutable('today')));
        [$from, $to] = SchoolYear::bounds($curso);
        $year = $years->findBySchoolYear($curso);

        return $this->render('guardia/history.html.twig', [
            'covers' => $covers->history($from, $to, null, null, null),
            'slotTimes' => $this->slotTimes($schedule, $year),
            'curso' => $curso,
            'years' => array_map(static fn (AcademicYear $ay): string => $ay->getSchoolYear(), $years->findAllOrdered()),
        ]);
    }

    /**
     * The coordinator's analytics dashboard. Several lenses over the course's covers: coverage health
     * (registered vs covered vs incident vs unassigned), fairness of the split (descriptive measures +
     * a Gini-based balance reading), monthly evolution, a weekday × period heatmap of where cover is
     * needed, absences by department and the busiest teachers on both sides. Read access is enough.
     */
    #[Route('/estadisticas', name: 'guardia_stats', methods: ['GET'])]
    public function stats(Request $request, GuardiaCoverRepository $covers, ScheduleEntryRepository $schedule, AcademicYearRepository $years, GuardiaStatistics $statistics): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::READ, Area::GUARDIAS);

        $courses = $years->findAllOrdered();

        // The periods to look at: any mix of whole courses and single terms, chosen with checkboxes.
        // With none chosen we default to the current course, so the screen is never empty. Every figure
        // is recomputed for each selected window, and with two or more periods they sit side by side.
        // Tolerate both ?p[]=… (normal) and a stray scalar ?p=…, without InputBag throwing on the latter.
        $requestedTokens = array_map(strval(...), (array) ($request->query->all()['p'] ?? []));
        $periods = $this->resolvePeriods($requestedTokens, $courses);
        if ([] === $periods) {
            $curso = SchoolYear::current(new \DateTimeImmutable('today'));
            [$from, $to] = SchoolYear::bounds($curso);
            $periods = [['token' => $curso, 'label' => $curso, 'from' => $from, 'to' => $to]];
        }
        $single = 1 === \count($periods);

        // One comparable KPI row per period (absences, coverage, fairness).
        $kpis = array_map(
            fn (array $p): array => ['token' => $p['token']] + $this->windowKpis($covers, $statistics, $p['label'], $p['from'], $p['to']),
            $periods,
        );

        // Absences by department and by teacher as matrices: a row per department/teacher, a cell per
        // period, sorted by the total across the selected periods (busiest first).
        $byDepartment = $this->comparisonMatrix($periods, static fn (array $p): array => array_map(
            static fn (array $r): array => ['key' => $r['name'], 'name' => $r['name'], 'total' => $r['total']],
            $covers->absencesByDepartment($p['from'], $p['to']),
        ));
        $absentRanking = \array_slice($this->comparisonMatrix($periods, static fn (array $p): array => array_map(
            static fn (array $r): array => ['key' => (string) $r['teacher']->getId(), 'name' => $r['teacher']->getFullName(), 'total' => $r['total']],
            $covers->absencesByTeacher(1000, $p['from'], $p['to']),
        )), 0, 15);

        // For one period the analytics rows feed BOTH the monthly evolution and the heatmap, so fetch
        // them once and share (avoids a duplicate full-window query on the default, unfiltered view).
        $singleRows = $single ? $covers->analyticsRows($periods[0]['from'], $periods[0]['to']) : null;

        // Evolution chart: for one period, the coverage breakdown over its calendar months; for several,
        // one series per period aligned on the month of the school year, so the same term of different
        // years (or two whole years) overlay and can be compared. Carries a matching data table.
        $evolution = $this->evolutionSpec($covers, $statistics, $periods, $single, $singleRows);

        // The per-teacher lenses (equity ranking, weekday × period heatmap) only make sense for one
        // window; a comparison of several drops them for the side-by-side tables and comparison charts.
        $singleExtras = [];
        if ($single) {
            $p = $periods[0];
            $ranking = $covers->coveredTotalsByTeacher($p['from'], $p['to']);
            $year = $years->findBySchoolYear(explode(':', $p['token'])[0]) ?? $years->findBySchoolYear(SchoolYear::current(new \DateTimeImmutable('today')));
            $singleExtras = [
                'ranking' => $ranking,
                'max' => $ranking[0]['total'] ?? 0,
                'equity' => $statistics->equity(array_map(static fn (array $r): int => $r['total'], $ranking)),
                'heatmap' => $statistics->heatmap($singleRows),
                'slotTimes' => $this->slotTimes($schedule, $year),
            ];
        }

        return $this->render('guardia/stats.html.twig', [
            'courses' => $courses,
            'periods' => $periods,
            'selectedTokens' => array_map(static fn (array $p): string => $p['token'], $periods),
            'single' => $single,
            'kpis' => $kpis,
            'evolution' => $evolution,
            'byDepartment' => $byDepartment,
            'absentRanking' => $absentRanking,
        ] + $singleExtras);
    }

    /**
     * The comparable KPI set for one date window (a term, a whole course): absences, coverage and the
     * fairness of the split, so several periods can be laid side by side.
     *
     * @return array{label: string, absences: int, covered: int, incidents: int, unassigned: int, coverageRate: int, teachers: int, mean: float, balance: string}
     */
    private function windowKpis(GuardiaCoverRepository $covers, GuardiaStatistics $statistics, string $label, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $summary = $covers->coverageSummary($from, $to);
        $equity = $statistics->equity(array_map(static fn (array $r): int => $r['total'], $covers->coveredTotalsByTeacher($from, $to)));

        return [
            'label' => $label,
            'absences' => $summary['absences'],
            'covered' => $summary['covered'],
            'incidents' => $summary['incidents'],
            'unassigned' => $summary['unassigned'],
            'coverageRate' => $summary['absences'] > 0 ? (int) round($summary['covered'] * 100 / $summary['absences']) : 0,
            'teachers' => $equity['count'],
            'mean' => $equity['mean'],
            'balance' => $equity['label'],
        ];
    }

    /**
     * Resolves period tokens into comparable date windows, in the order given and de-duplicated. A token
     * is a school year ("2025-2026") for the whole course, or "2025-2026:2" for a single term (needs the
     * course's {@see AcademicYear} for the term dates; unknown or malformed tokens are dropped).
     *
     * @param list<string>      $tokens  the requested period tokens
     * @param list<AcademicYear> $courses the defined courses, to resolve term dates
     *
     * @return list<array{token: string, label: string, from: \DateTimeImmutable, to: \DateTimeImmutable}> the windows
     */
    private function resolvePeriods(array $tokens, array $courses): array
    {
        $byYear = [];
        foreach ($courses as $ay) {
            $byYear[$ay->getSchoolYear()] = $ay;
        }
        $termLabels = [1 => '1er trim.', 2 => '2º trim.', 3 => '3er trim.'];

        $periods = [];
        $seen = [];
        foreach ($tokens as $token) {
            $parts = explode(':', $token);
            $schoolYear = $parts[0];
            if (1 !== preg_match('/^\d{4}-\d{4}$/', $schoolYear)) {
                continue;
            }
            $term = isset($parts[1]) ? (int) $parts[1] : 0;
            $ay = $byYear[$schoolYear] ?? null;

            if ($term >= 1 && $term <= 3 && $ay instanceof AcademicYear) {
                $canonical = $schoolYear.':'.$term;
                $from = $ay->getTermStart($term);
                $to = $ay->getTermEnd($term);
                $label = sprintf('%s · %s', $schoolYear, $termLabels[$term]);
            } else {
                $canonical = $schoolYear;
                [$from, $to] = SchoolYear::bounds($schoolYear);
                $label = $schoolYear;
            }
            if (isset($seen[$canonical])) {
                continue;
            }
            $seen[$canonical] = true;
            $periods[] = ['token' => $canonical, 'label' => $label, 'from' => $from, 'to' => $to];
        }

        return $periods;
    }

    /**
     * Builds a comparison matrix: one row per distinct entity (department, teacher…), a cell per period
     * with its count, ordered by the total across all periods (busiest first). The per-period fetcher
     * returns rows of {@code {key, name, total}} for one window.
     *
     * @param list<array{token: string, label: string, from: \DateTimeImmutable, to: \DateTimeImmutable}> $periods the windows
     * @param callable(array{token: string, label: string, from: \DateTimeImmutable, to: \DateTimeImmutable}): list<array{key: string, name: string, total: int}> $fetch per-period fetcher
     *
     * @return list<array{name: string, cells: list<int>, total: int}> the matrix rows, busiest first
     */
    private function comparisonMatrix(array $periods, callable $fetch): array
    {
        $names = [];
        $byPeriod = [];
        $totals = [];
        foreach ($periods as $p) {
            foreach ($fetch($p) as $row) {
                $names[$row['key']] = $row['name'];
                $byPeriod[$row['key']][$p['token']] = $row['total'];
                $totals[$row['key']] = ($totals[$row['key']] ?? 0) + $row['total'];
            }
        }
        arsort($totals);

        $rows = [];
        foreach ($totals as $key => $total) {
            $cells = [];
            foreach ($periods as $p) {
                $cells[] = $byPeriod[$key][$p['token']] ?? 0;
            }
            $rows[] = ['name' => $names[$key], 'cells' => $cells, 'total' => $total];
        }

        return $rows;
    }

    /**
     * The evolution-chart spec for the selected periods, plus a matching data table.
     *
     * For a single period it is the coverage breakdown (covered / unassigned / incidents) over that
     * period's calendar months. For several it is one series per period, aligned on the month of the
     * school year (Sep…Aug), so the same term of different years — or two whole years — overlay and can
     * actually be compared, instead of being concatenated into one misleading trend.
     *
     * @param list<array{token: string, label: string, from: \DateTimeImmutable, to: \DateTimeImmutable}> $periods    the windows
     * @param list<array{date: \DateTimeImmutable, slot: int, assigned: bool, incident: bool}>|null       $singleRows the single period's analytics rows, already fetched to share with the heatmap (single only)
     *
     * @return array<string, mixed> a spec consumed by guardia-charts.js: for 'status' it carries ready
     *                              series + a data table; for 'periods' it carries every metric per
     *                              period so the client can switch which one to compare, plus a
     *                              server-rendered default-metric table (fallback without JS)
     */
    private function evolutionSpec(GuardiaCoverRepository $covers, GuardiaStatistics $statistics, array $periods, bool $single, ?array $singleRows = null): array
    {
        if ($single) {
            $months = $statistics->byMonth($singleRows ?? $covers->analyticsRows($periods[0]['from'], $periods[0]['to']));

            return [
                'kind' => 'status',
                'categories' => array_map(static fn (array $m): string => $m['label'], $months),
                'series' => [
                    ['name' => 'Cubiertas', 'data' => array_map(static fn (array $m): int => $m['covered'], $months)],
                    ['name' => 'Sin asignar', 'data' => array_map(static fn (array $m): int => $m['unassigned'], $months)],
                    ['name' => 'Incidencias', 'data' => array_map(static fn (array $m): int => $m['incidents'], $months)],
                ],
                'table' => [
                    'header' => 'Mes',
                    'columns' => ['Cubiertas', 'Sin asignar', 'Incidencias', 'Total'],
                    'rows' => array_map(static fn (array $m): array => ['label' => $m['label'], 'cells' => [$m['covered'], $m['unassigned'], $m['incidents'], $m['absences']]], $months),
                ],
            ];
        }

        // Compare: absences per period keyed by school-year month rank (Sep = 1 … Aug = 12), so the
        // periods line up on the same x regardless of their calendar year. A month a period does NOT
        // span stays null (a gap in the line / "—" in the table), never 0 — that would falsely read as
        // "zero absences" for months that simply are not part of that period (e.g. a term vs a course).
        // Keyed by the canonical token (not the display label), consistent with comparisonMatrix().
        $rankOf = [9 => 1, 10 => 2, 11 => 3, 12 => 4, 1 => 5, 2 => 6, 3 => 7, 4 => 8, 5 => 9, 6 => 10, 7 => 11, 8 => 12];
        $labelByRank = [];
        $byPeriod = [];
        $labelByToken = [];
        foreach ($periods as $p) {
            $labelByToken[$p['token']] = $p['label'];
            $months = [];
            foreach ($statistics->byMonth($covers->analyticsRows($p['from'], $p['to'])) as $m) {
                $months[$rankOf[(int) substr($m['key'], 5, 2)]] = $m; // el bucket lleva los 4 valores
            }
            // Meses que el periodo abarca (para distinguir "mes sin ausencias" → 0 de "mes fuera" → null).
            $inRange = [];
            for ($cursor = $p['from']->modify('first day of this month'); $cursor <= $p['to']; $cursor = $cursor->modify('+1 month')) {
                $month = (int) $cursor->format('n');
                $inRange[$rankOf[$month]] = true;
                $labelByRank[$rankOf[$month]] = $statistics->monthAbbrev($month);
            }
            $byPeriod[$p['token']] = ['months' => $months, 'inRange' => $inRange];
        }
        ksort($labelByRank);
        $ranks = array_keys($labelByRank);
        $tokens = array_keys($byPeriod);
        $metrics = ['absences' => 'Ausencias', 'covered' => 'Cubiertas', 'unassigned' => 'Sin asignar', 'incidents' => 'Incidencias'];
        $default = 'absences';

        // One metric of one period at a month rank: its value (0 if no absences) when the period spans
        // that month, or null when it does not (a gap in the line, never a misleading 0).
        $cell = static fn (string $token, int $rank, string $key): ?int => isset($byPeriod[$token]['inRange'][$rank]) ? ($byPeriod[$token]['months'][$rank][$key] ?? 0) : null;

        return [
            'kind' => 'periods',
            'categories' => array_values($labelByRank),
            // The user picks which metric to compare (the chart shows one at a time — several periods ×
            // several metrics on one line chart would be unreadable). All are shipped so switching is
            // instant, no round-trip.
            'metrics' => $metrics,
            'defaultMetric' => $default,
            'periods' => array_map(
                static fn (string $token): array => [
                    'name' => $labelByToken[$token],
                    'values' => array_combine(
                        array_keys($metrics),
                        array_map(static fn (string $key): array => array_map(static fn (int $rank): ?int => $cell($token, $rank, $key), $ranks), array_keys($metrics)),
                    ),
                ],
                $tokens,
            ),
            // Default-metric table rendered server-side so the data survives without JS (the selector
            // then rewrites it client-side); mirrors the single-period table shape.
            'table' => [
                'header' => 'Mes',
                'columns' => array_map(static fn (string $token): string => $labelByToken[$token], $tokens),
                'rows' => array_map(
                    static fn (int $rank): array => ['label' => $labelByRank[$rank], 'cells' => array_map(static fn (string $token): ?int => $cell($token, $rank, $default), $tokens)],
                    $ranks,
                ),
            ],
        ];
    }

    /**
     * The per-teacher guardia figures as a CSV (Excel-friendly, UTF-8 BOM): every teacher who covered
     * or was absent, with guardias covered and absences. Read access to the guardia area is enough.
     */
    #[Route('/estadisticas.csv', name: 'guardia_stats_csv', methods: ['GET'])]
    public function statsCsv(GuardiaCoverRepository $covers): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::READ, Area::GUARDIAS);

        // Union of both rankings keyed by teacher, so a teacher shows up whether they covered, were
        // absent, or both.
        $byTeacher = [];
        foreach ($covers->coveredTotalsByTeacher() as $row) {
            $byTeacher[$row['teacher']->getId()] = ['name' => $row['teacher']->getFullName(), 'covered' => $row['total'], 'absences' => 0];
        }
        foreach ($covers->absencesByTeacher(100000) as $row) {
            $id = $row['teacher']->getId();
            $byTeacher[$id] ??= ['name' => $row['teacher']->getFullName(), 'covered' => 0, 'absences' => 0];
            $byTeacher[$id]['absences'] = $row['total'];
        }
        usort($byTeacher, static fn (array $a, array $b): int => $b['covered'] <=> $a['covered'] ?: strcasecmp($a['name'], $b['name']));

        $lines = ["\u{FEFF}Profesor;Guardias cubiertas;Ausencias"];
        foreach ($byTeacher as $r) {
            $lines[] = sprintf('"%s";%d;%d', str_replace('"', '""', $r['name']), $r['covered'], $r['absences']);
        }

        return new Response(implode("\r\n", $lines)."\r\n", Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="guardias-por-profesor.csv"',
        ]);
    }

    /**
     * The "apuntar ausencia" form: pick the absent teacher and the day, then tick the periods missed
     * straight from that teacher's real timetable for that weekday, leaving a task per class. Choosing
     * the teacher or the day reloads the screen (GET) so the class list matches. A coordinator (guardias
     * WRITE) can register any teacher; any other teacher may only report their own absence, so the
     * picker is limited to themselves. Reachable prefilled with {@code ?teacher=<id>} for a coordinator.
     */
    #[Route('/ausencia/nueva', name: 'guardia_absence_new', methods: ['GET'])]
    public function newAbsence(Request $request, #[CurrentUser] User $user, UserRepository $users, ScheduleEntryRepository $schedule, AcademicYearRepository $years): Response
    {
        $canManage = $this->isGranted(AreaVoter::WRITE, Area::GUARDIAS);
        $date = $this->dateFromRequest($request);
        $schoolYear = SchoolYear::current($date);
        $year = $years->findBySchoolYear($schoolYear);
        $weekday = Weekday::from((int) $date->format('N'));

        // A coordinator picks from everyone; anyone else can only be themselves.
        $selected = $canManage
            ? (($id = (int) $request->query->get('teacher')) > 0 ? $users->find($id) : null)
            : $user;

        // The selected teacher's classes that weekday: the rows to tick and leave a task for. Empty
        // until a teacher is chosen, when there is no timetable, or they teach nothing that day.
        $dayClasses = ($selected instanceof User && $year instanceof AcademicYear)
            ? $schedule->lectiveDayFor($year, $selected, $weekday)
            : [];

        return $this->render('guardia/absence_new.html.twig', [
            'date' => $date,
            'weekday' => $weekday,
            'schoolYear' => $schoolYear,
            'hasTimetable' => $year instanceof AcademicYear,
            'allTeachers' => $canManage ? $users->findBy([], ['fullName' => 'ASC']) : [$user],
            'selectedTeacher' => $selected?->getId(),
            'dayClasses' => $dayClasses,
        ]);
    }

    /**
     * Registers the absence for the periods ticked and lets {@see AbsenceRegistrar} generate a cover per
     * taught period (with its own task) and run the equitable assignment. Free periods and
     * already-registered ones are reported as skipped. A non-coordinator may only register their own
     * absence (the posted teacher is ignored for them).
     */
    #[Route('/ausencia', name: 'guardia_absence_create', methods: ['POST'])]
    public function createAbsence(Request $request, #[CurrentUser] User $user, UserRepository $users, AcademicYearRepository $years, AbsenceRegistrar $registrar): Response
    {
        $this->assertCsrf($request, 'guardia_absence_create');

        $date = $this->dateFromRequest($request);
        $year = $years->findBySchoolYear(SchoolYear::current($date));
        if (!$year instanceof AcademicYear) {
            $this->addFlash('error', sprintf('No hay horario importado para el curso %s. Impórtalo antes de registrar ausencias.', SchoolYear::current($date)));

            return $this->redirectToRoute('guardia_index', ['date' => $date->format('Y-m-d')]);
        }

        // A coordinator registers any teacher; anyone else may only report their own absence.
        $teacher = $this->isGranted(AreaVoter::WRITE, Area::GUARDIAS)
            ? $users->find((int) $request->request->get('absent_teacher'))
            : $user;
        if (!$teacher instanceof User) {
            $this->addFlash('error', 'Elige el profesor ausente.');

            return $this->redirectToRoute('guardia_absence_new', ['date' => $date->format('Y-m-d')]);
        }

        // The periods ticked on the class list, each with its own optional task.
        $slotIndexes = array_map(intval(...), $request->request->all('slots'));
        if ([] === $slotIndexes) {
            $this->addFlash('error', 'Marca al menos una hora en la que falta el profesor.');

            return $this->redirectToRoute('guardia_absence_new', ['date' => $date->format('Y-m-d'), 'teacher' => $teacher->getId()]);
        }

        /** @var array<int|string, mixed> $rawTasks */
        $rawTasks = $request->request->all('task');
        $taskBySlot = [];
        foreach ($slotIndexes as $slotIndex) {
            $note = trim((string) ($rawTasks[$slotIndex] ?? ''));
            if ('' !== $note) {
                $taskBySlot[$slotIndex] = $note;
            }
        }

        $result = $registrar->register($year, $teacher, $date, $slotIndexes, null, $taskBySlot);

        $this->flashRegistration($teacher, $result);

        return $this->backToParte($date, $result->createdSlots[0] ?? ($slotIndexes[0] ?? 0));
    }

    /**
     * Flashes a summary of the registration: created covers plus any periods skipped (free or already
     * registered).
     *
     * @param User                      $teacher the absent teacher
     * @param AbsenceRegistrationResult $result  the registration outcome
     */
    private function flashRegistration(User $teacher, AbsenceRegistrationResult $result): void
    {
        if (0 === $result->createdCount()) {
            $this->addFlash('error', sprintf('No se generó ninguna guardia para %s: no da clase esas horas o ya estaban en el parte.', $teacher->getFullName()));

            return;
        }

        $msg = sprintf('%d guardia(s) generada(s) para %s.', $result->createdCount(), $teacher->getFullName());
        if ($result->skippedFree > 0) {
            $msg .= sprintf(' %d hora(s) libre(s) omitida(s).', $result->skippedFree);
        }
        if ($result->skippedExisting > 0) {
            $msg .= sprintf(' %d ya estaba(n) en el parte.', $result->skippedExisting);
        }
        $this->addFlash('success', $msg);
    }

    /**
     * Re-runs the equitable assignment for a period, filling any still-unassigned covers.
     */
    #[Route('/asignar', name: 'guardia_auto_assign', methods: ['POST'])]
    public function autoAssign(Request $request, GuardiaScheduler $scheduler, AcademicYearRepository $years): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);
        $this->assertCsrf($request, 'guardia_auto_assign');
        $date = $this->dateFromRequest($request);
        $slotIndex = (int) $request->request->get('slot');

        $year = $years->findBySchoolYear(SchoolYear::current($date));
        if (!$year instanceof AcademicYear) {
            $this->addFlash('error', sprintf('No hay horario importado para el curso %s.', SchoolYear::current($date)));

            return $this->backToParte($date, $slotIndex);
        }

        $assigned = $scheduler->autoAssign($year, $date, $slotIndex);
        $this->addFlash('success', 0 === $assigned ? 'No había guardias pendientes de asignar.' : sprintf('%d guardia(s) asignada(s).', $assigned));

        return $this->backToParte($date, $slotIndex);
    }

    /**
     * The read-only detail of a single guardia: its group/room, day and time, the absent teacher, the
     * task left and how it ended (covered / incident / unassigned). Open to the assigned guardia teacher
     * for THEIR own cover (self-service, no WRITE needed) and to the coordinator (READ). This is where
     * "mis guardias" links each row; coordinators additionally get a link to modify it.
     */
    #[Route('/{id}/ver', name: 'guardia_cover_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function showCover(GuardiaCover $cover, #[CurrentUser] User $user, ScheduleEntryRepository $schedule, AcademicYearRepository $years): Response
    {
        // A teacher may see the guardia assigned to them; anyone else needs read access to the area.
        $isOwner = $cover->getAssignedGuardia()?->getId() === $user->getId();
        if (!$isOwner && !$this->isGranted(AreaVoter::READ, Area::GUARDIAS)) {
            throw $this->createAccessDeniedException();
        }

        $year = $years->findBySchoolYear(SchoolYear::current($cover->getDate()));

        return $this->render('guardia/cover_show.html.twig', [
            'cover' => $cover,
            'slotTimes' => $this->slotTimes($schedule, $year),
            'canEdit' => $this->isGranted(AreaVoter::WRITE, Area::GUARDIAS),
        ]);
    }

    /**
     * The single "modificar guardia" screen: change the assigned substitute and/or flag that the cover
     * did not happen, always stating a reason. It is the only way to touch a cover by hand — the centre
     * wants the system as automatic as possible, so every manual change is deliberate and traceable.
     * Shows the cover's context and its event log (the audit trail of what changed and why).
     */
    #[Route('/{id}/modificar', name: 'guardia_cover_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function editCover(GuardiaCover $cover, ScheduleEntryRepository $schedule, AcademicYearRepository $years, AuditLogRepository $audit, GuardiaActivityPresenter $activity): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);

        $year = $years->findBySchoolYear(SchoolYear::current($cover->getDate()));
        $weekday = Weekday::from((int) $cover->getDate()->format('N'));
        $pool = $year instanceof AcademicYear ? $schedule->dutyPoolAt($year, $weekday, $cover->getSlotIndex()) : [];

        return $this->render('guardia/cover_edit.html.twig', [
            'cover' => $cover,
            'pool' => $pool,
            'slotTimes' => $this->slotTimes($schedule, $year),
            'events' => $activity->present($audit->findForSubject('GuardiaCover', (string) $cover->getId())),
        ]);
    }

    /**
     * Applies a manual change to a cover: reassigns the substitute (empty clears it) and/or toggles the
     * "did not happen" flag, with a mandatory reason recorded in the event log ({@see AuditContext}).
     * Notifies the substitute when it actually changes.
     */
    #[Route('/{id}/modificar', name: 'guardia_cover_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateCover(GuardiaCover $cover, Request $request, UserRepository $users, EntityManagerInterface $em, GuardiaAssignmentNotifier $notifier, AuditContext $audit): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);
        $this->assertCsrf($request, 'guardia_cover_update'.$cover->getId());

        $reason = trim((string) $request->request->get('motivo'));
        if ('' === $reason) {
            $this->addFlash('error', 'Indica el motivo del cambio: queda registrado en el histórico de la guardia.');

            return $this->redirectToRoute('guardia_cover_edit', ['id' => $cover->getId()]);
        }

        $teacherId = $request->request->get('guardia');
        $previous = $cover->getAssignedGuardia();
        $cover->setAssignedGuardia('' !== (string) $teacherId ? $users->find((int) $teacherId) : null);
        $cover->setNotCovered($request->request->getBoolean('not_covered'));
        // setTaskNote normaliza cadena vacía a null, así que "borrar la tarea" también queda soportado.
        $cover->setTaskNote((string) $request->request->get('task_note'));

        // The reason rides along into the audit entry this flush produces (see EntityAuditSubscriber).
        $audit->setReason($reason);
        $em->flush();

        // Notify only when the substitute actually changes (reselecting the same one does not notify).
        if ($cover->getAssignedGuardia() !== $previous) {
            $notifier->notifyAssigned($cover);
        }
        $this->addFlash('success', 'Guardia modificada y registrada en el histórico.');

        return $this->backToParte($cover->getDate(), $cover->getSlotIndex());
    }

    /**
     * Deletes a parte line.
     */
    #[Route('/{id}/borrar', name: 'guardia_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(GuardiaCover $cover, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);
        $this->assertCsrf($request, 'guardia_delete'.$cover->getId());

        $date = $cover->getDate();
        $slotIndex = $cover->getSlotIndex();
        try {
            $em->remove($cover);
            $em->flush();
            $this->addFlash('success', 'Línea del parte eliminada.');
        } catch (\Throwable) {
            $this->addFlash('error', 'No se pudo borrar la línea del parte. Inténtalo de nuevo.');
        }

        return $this->backToParte($date, $slotIndex);
    }

    /**
     * Reads the requested date from the query/post ("Y-m-d"), falling back to today on absence or a
     * bad value.
     *
     * @param Request $request the current request
     *
     * @return \DateTimeImmutable the date to show (time set to midnight)
     */
    private function dateFromRequest(Request $request): \DateTimeImmutable
    {
        $raw = (string) ($request->query->get('date') ?? $request->request->get('date'));
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

        return false !== $date ? $date : new \DateTimeImmutable('today');
    }

    /**
     * Reads the requested period index, defaulting to the day's first period when absent or unknown.
     *
     * @param Request                                                                          $request the current request
     * @param list<array{index: int, startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}> $slots   the available periods
     *
     * @return int the period index to show
     */
    private function slotFromRequest(Request $request, array $slots): int
    {
        if ($request->query->has('slot')) {
            return (int) $request->query->get('slot');
        }

        return $slots[0]['index'] ?? 0;
    }

    /**
     * The given course's periods keyed by their index, so a view holding only a {@code slotIndex}
     * (e.g. a cover) can print the period's start/end time without another query per row. Empty when
     * no course (hence no timetable) applies.
     *
     * @param ScheduleEntryRepository $schedule the timetable repository
     * @param AcademicYear|null       $year     the course whose periods to read, or null
     *
     * @return array<int, array{startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}> times by slot index
     */
    private function slotTimes(ScheduleEntryRepository $schedule, ?AcademicYear $year): array
    {
        if (null === $year) {
            return [];
        }

        $times = [];
        foreach ($schedule->distinctSlots($year) as $slot) {
            $times[$slot['index']] = ['startsAt' => $slot['startsAt'], 'endsAt' => $slot['endsAt']];
        }

        return $times;
    }

    /**
     * Validates the CSRF token for an action or denies access.
     *
     * @param Request $request the current request
     * @param string  $id      the CSRF token id
     */
    private function assertCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
    }

    /**
     * Redirects back to the parte for a date and period.
     *
     * @param \DateTimeImmutable $date      the day
     * @param int                $slotIndex the period index
     *
     * @return Response the redirect
     */
    private function backToParte(\DateTimeImmutable $date, int $slotIndex): Response
    {
        return $this->redirectToRoute('guardia_index', ['date' => $date->format('Y-m-d'), 'slot' => $slotIndex]);
    }
}
