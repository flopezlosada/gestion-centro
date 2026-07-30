<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AcademicYear;
use App\Entity\GuardiaCover;
use App\Entity\GuardiaSupport;
use App\Entity\ScheduleEntry;
use App\Entity\User;
use App\Enum\Area;
use App\Enum\GuardiaDutyBand;
use App\Enum\ScheduleActivityKind;
use App\Enum\Weekday;
use App\Guardia\AbsenceRegistrar;
use App\Guardia\AbsenceRegistrationResult;
use App\Guardia\AssignmentRefused;
use App\Guardia\BreakDutyGapRegistrar;
use App\Guardia\GuardiaScheduler;
use App\Guardia\GuardiaStatistics;
use App\Repository\AcademicYearRepository;
use App\Repository\AuditLogRepository;
use App\Repository\BreakDutyAssignmentRepository;
use App\Repository\GuardiaCoverRepository;
use App\Repository\GuardiaSupportRepository;
use App\Repository\ScheduleEntryRepository;
use App\Repository\TimeSlotRepository;
use App\Repository\UserRepository;
use App\Security\Voter\AreaVoter;
use App\Service\FileUploader;
use App\Service\GuardiaAssignmentNotifier;
use App\Support\AuditContext;
use App\Support\DocumentUpload;
use App\Support\GuardiaActivityPresenter;
use App\Support\GuardiaDate;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
    use GuardiaParteTrait;

    /** Private storage subdirectory for the task documents left with an absence. */
    private const string TASK_DOCUMENT_SUBDIR = 'guardia-tasks';

    /**
     * Shows the parte for a date and period, plus the on-call pool. Date and period come from the
     * query string (today and the first period of the day by default). Uncovered lines are listed
     * first — they are the ones that need action — and the coverage figures head the panel.
     */
    #[Route('', name: 'guardia_index', methods: ['GET'])]
    public function index(Request $request, ScheduleEntryRepository $schedule, GuardiaCoverRepository $covers, GuardiaSupportRepository $support, AcademicYearRepository $years, UserRepository $users, GuardiaScheduler $scheduler): Response
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

        // The groups each on-call teacher is already covering this period, so the pool panel can tell
        // who is busy from who is still free at a glance. A LIST per teacher, not a single name: in
        // deficit the same teacher minds several groups, and keeping only the last one would report
        // the parte wrongly on the very screen meant to expose the overload.
        //
        // Alongside it, how many guardias that actually COSTS them: several groups folded into one
        // grouping are one guardia between them (the centre's rule, mirrored in
        // GuardiaCoverRepository::WORK_UNIT), so once they are together in a room the overload is
        // resolved and must stop being flagged as one.
        $assignedHere = [];
        $workUnits = [];
        foreach ($parte as $cover) {
            $guardia = $cover->getAssignedGuardia();
            if (null !== $guardia && null !== $guardia->getId()) {
                $assignedHere[$guardia->getId()][] = $cover->getGroupName() ?? 'un grupo';
                $unit = null !== $cover->getGrouping() ? 'g'.$cover->getGrouping()->getId() : 'c'.$cover->getId();
                $workUnits[$guardia->getId()][$unit] = true;
            }
        }
        $workUnits = array_map(\count(...), $workUnits);

        // Uncovered first, keeping the repository's alphabetical order within each group: what needs
        // assigning must not be buried under what is already sorted out.
        $ordered = $parte;
        usort($ordered, static fn (GuardiaCover $a, GuardiaCover $b): int => (null === $a->getAssignedGuardia() ? 0 : 1) <=> (null === $b->getAssignedGuardia() ? 0 : 1));

        $uncovered = \count(array_filter($parte, static fn (GuardiaCover $c): bool => null === $c->getAssignedGuardia()));
        $absentIds = $covers->absentTeacherIdsAt($date, $slotIndex);
        $poolView = $this->poolView($pool, $support->findForSlot($date, $slotIndex), $absentIds, $assignedHere, $covers->loadBySlot($slotIndex));

        return $this->render('guardia/index.html.twig', [
            'date' => $date,
            'weekday' => $weekday,
            'schoolYear' => $schoolYear,
            'slots' => $slots,
            'slotIndex' => $slotIndex,
            'covers' => $ordered,
            'pool' => $poolView,
            'assignedHere' => $assignedHere,
            'workUnits' => $workUnits,
            'uncovered' => $uncovered,
            'covered' => \count($parte) - $uncovered,
            // Who the split would pick, least loaded first: feeds the per-cover assignment sheet. With
            // nothing left to cover no sheet is rendered, so the pool queries are not worth running.
            'candidates' => ($uncovered > 0 && $year instanceof AcademicYear) ? $scheduler->availableFor($year, $date, $slotIndex, $parte) : [],
            'deficit' => $this->deficitSummary($poolView, $workUnits, $uncovered),
            // Everyone, to sign up a colleague as support; flagged with the class the timetable says
            // they are teaching that hour, because that clash is the normal case and only a human can
            // judge it (their Bachillerato group has finished lessons — the timetable does not know).
            'supportPicker' => $this->isGranted(AreaVoter::WRITE, Area::GUARDIAS) ? $users->findBy([], ['fullName' => 'ASC']) : [],
            'teachingHere' => $year instanceof AcademicYear ? $schedule->lectiveGroupsByTeacherAt($year, $weekday, $slotIndex) : [],
        ]);
    }

    /**
     * One row per person who could cover this period, whatever brings them here: the weekly rota, a
     * collaborator slot, or a hand-added support arrangement for that very day. Same shape for all three
     * so the pool panel and the assignment sheet read one list instead of stitching two together, and so
     * a support teacher is visible exactly where a coordinator looks for "who can cover this hour".
     *
     * A teacher on the rota who is ALSO signed up as support keeps the rota label (a duty, not a favour)
     * and appears once — the same precedence {@see GuardiaScheduler} applies when building candidates.
     *
     * @param ScheduleEntry[]          $pool         the period's duty entries (a teacher may appear twice)
     * @param GuardiaSupport[]         $support      the hand-added arrangements for that date and period
     * @param list<int>                $absentIds    ids of teachers themselves absent that period
     * @param array<int, list<string>> $assignedHere teacher id → groups they already cover that period
     * @param array<int, int>          $slotLoad     teacher id → guardias done at this period, all course
     *
     * @return list<array{teacher: User, band: GuardiaDutyBand, note: ?string, supportId: ?int, absent: bool, groups: list<string>, load: int}> the rows, rota first then by name
     */
    private function poolView(array $pool, array $support, array $absentIds, array $assignedHere, array $slotLoad): array
    {
        $rows = [];
        foreach ($support as $entry) {
            $id = (int) $entry->getTeacher()->getId();
            $rows[$id] = ['teacher' => $entry->getTeacher(), 'band' => GuardiaDutyBand::SUPPORT, 'note' => $entry->getNote(), 'supportId' => $entry->getId()];
        }
        foreach ($pool as $entry) {
            $id = (int) $entry->getTeacher()->getId();
            $band = ScheduleActivityKind::COLLABORATOR === $entry->getKind() ? GuardiaDutyBand::COLLABORATOR : GuardiaDutyBand::GUARDIA;
            if (GuardiaDutyBand::GUARDIA === ($rows[$id]['band'] ?? null)) {
                continue;
            }
            $rows[$id] = ['teacher' => $entry->getTeacher(), 'band' => $band, 'note' => null, 'supportId' => null];
        }

        $view = [];
        foreach ($rows as $id => $row) {
            $view[] = $row + [
                'absent' => \in_array($id, $absentIds, true),
                'groups' => $assignedHere[$id] ?? [],
                'load' => $slotLoad[$id] ?? 0,
            ];
        }
        usort($view, static fn (array $a, array $b): int => $a['band']->rank() <=> $b['band']->rank()
            ?: strcasecmp($a['teacher']->getFullName(), $b['teacher']->getFullName()));

        return $view;
    }

    /**
     * The staffing arithmetic of one period, so the parte can say out loud when there are more absences
     * than people to cover them instead of quietly doubling somebody up: how many people are actually
     * free, how many groups are still open, how many of those can only be covered by giving a colleague
     * a second guardia, and how many second guardias are already handed out.
     *
     * {@code doubled} matters as much as {@code missing}: once the split has run, nothing is left
     * uncovered and the shortfall reads as zero, yet three teachers may be minding two guardias each. The
     * warning has to survive the split, or the screen hides exactly what it is there to show.
     *
     * It is counted in guardias, not in groups, so grouping several classes into one room CLEARS it —
     * which is the point of the warning: it is there to be acted on, not to nag for ever.
     *
     * @param list<array{teacher: User, band: GuardiaDutyBand, note: ?string, supportId: ?int, absent: bool, groups: list<string>, load: int}> $poolView who could cover this period
     * @param array<int, int>                                                                                                                 $workUnits teacher id → guardias it costs them here
     * @param int                                                                                                                             $uncovered groups still without a substitute
     *
     * @return array{free: int, uncovered: int, missing: int, doubled: int, extra: int} free people, open
     *                                                                                 groups, shortfall,
     *                                                                                 teachers doubling up
     *                                                                                 and extra guardias they carry
     */
    private function deficitSummary(array $poolView, array $workUnits, int $uncovered): array
    {
        $free = \count(array_filter($poolView, static fn (array $row): bool => !$row['absent'] && [] === $row['groups']));
        $doubling = array_filter($workUnits, static fn (int $units): bool => $units > 1);

        return [
            'free' => $free,
            'uncovered' => $uncovered,
            'missing' => max(0, $uncovered - $free),
            'doubled' => \count($doubling),
            'extra' => array_sum(array_map(static fn (int $units): int => $units - 1, $doubling)),
        ];
    }

    /**
     * The teacher's own "mis guardias": today's guardias front and centre (period time, group, room,
     * absent teacher and any task left), plus the ones coming up on later days. Shows only their own.
     *
     * Their break duty rota comes too, and as a standing fact rather than a list of days: it is fixed for
     * the whole course, so what the teacher needs is "los martes, patio, 11:10–11:35" once, not one row
     * per Tuesday of the year.
     */
    #[Route('/mias', name: 'guardia_mine', methods: ['GET'])]
    public function mine(#[CurrentUser] User $user, GuardiaCoverRepository $covers, ScheduleEntryRepository $schedule, AcademicYearRepository $years, BreakDutyAssignmentRepository $breakDuties, TimeSlotRepository $timeSlots): Response
    {
        $today = new \DateTimeImmutable('today');
        $now = new \DateTimeImmutable('now');
        $year = $years->findBySchoolYear(SchoolYear::current($today));
        $slotTimes = $this->slotTimes($schedule, $year);

        return $this->render('guardia/mine.html.twig', [
            'today' => $this->buildTodayView($covers->findAssignedTo($user, $today), $slotTimes, $now),
            'upcoming' => $this->groupByDay($covers->findUpcomingAssignedTo($user, $today->modify('+1 day')), $today),
            'slotTimes' => $slotTimes,
            'breakDuties' => $year instanceof AcademicYear ? $breakDuties->findByTeacher($year, $user) : [],
            'breakSlots' => $timeSlots->findBreaksByYear($year instanceof AcademicYear ? $year : null),
            'todayWeekday' => Weekday::from((int) $today->format('N')),
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
            if ($cover->hasTask()) {
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
    public function newAbsence(Request $request, #[CurrentUser] User $user, UserRepository $users, ScheduleEntryRepository $schedule, AcademicYearRepository $years, BreakDutyGapRegistrar $breakGaps, TimeSlotRepository $timeSlots): Response
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

        // The selected teacher's classes that weekday, one row PER PERIOD (a multi-group activity folds
        // its several classes into one row, since it is one guardia to cover). Empty until a teacher is
        // chosen, when there is no timetable, or they teach nothing that day.
        $dayClasses = ($selected instanceof User && $year instanceof AcademicYear)
            ? $this->groupClassesBySlot($schedule->lectiveDayFor($year, $selected, $weekday))
            : [];

        // The recreo the absence would leave unwatched, if this teacher is on the break rota that weekday.
        // Offered as its own tick rather than inferred: a recreo is nobody's teaching period, so it is
        // absent from the class list above, and the centre's rule (it is NOT re-covered, the equipo
        // directivo is alerted) makes it something the person registering should see they are triggering.
        $breakDuty = ($selected instanceof User && $year instanceof AcademicYear)
            ? $breakGaps->dutyOn($year, $selected, $date)
            : null;

        return $this->render('guardia/absence_new.html.twig', [
            'date' => $date,
            'weekday' => $weekday,
            'schoolYear' => $schoolYear,
            'hasTimetable' => $year instanceof AcademicYear,
            'allTeachers' => $canManage ? $users->findBy([], ['fullName' => 'ASC']) : [$user],
            'selectedTeacher' => $selected?->getId(),
            // Name of the chosen teacher, so the picker can show their monogram next to the select.
            'selectedTeacherName' => $selected?->getFullName(),
            'dayClasses' => $dayClasses,
            'breakDuty' => $breakDuty,
            'breakSlots' => $timeSlots->findBreaksByYear($year instanceof AcademicYear ? $year : null),
        ]);
    }

    /**
     * Groups a teacher's day of classes by period for the "apuntar ausencia" list: one row per period,
     * folding the several classes a multi-group activity holds at the same time (e.g. a whole-level
     * session in the assembly hall) into a single row that lists every group and its room. One period
     * is one guardia to cover, so the form offers one row per period — not one per group, which would
     * repeat the same hour and make the per-class task inputs collide.
     *
     * @param ScheduleEntry[] $entries the teacher's lective classes that weekday, any order
     *
     * @return list<array{slotIndex: int, startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable, subjectName: ?string, groups: list<string>, room: ?string, groupCount: int}> one row per period, earliest first
     */
    private function groupClassesBySlot(array $entries): array
    {
        $bySlot = [];
        foreach ($entries as $entry) {
            $i = $entry->getSlotIndex();
            $bySlot[$i] ??= ['slotIndex' => $i, 'startsAt' => $entry->getStartsAt(), 'endsAt' => $entry->getEndsAt(), 'subjectName' => $entry->getSubjectName(), 'groups' => [], 'rooms' => []];
            if (null !== $entry->getGroupName()) {
                $bySlot[$i]['groups'][] = $entry->getGroupName();
            }
            if (null !== $entry->getRoomName()) {
                $bySlot[$i]['rooms'][] = $entry->getRoomName();
            }
        }
        ksort($bySlot);

        return array_values(array_map(static function (array $r): array {
            $groups = array_values(array_unique($r['groups']));
            $rooms = array_values(array_unique($r['rooms']));

            return [
                'slotIndex' => $r['slotIndex'],
                'startsAt' => $r['startsAt'],
                'endsAt' => $r['endsAt'],
                'subjectName' => $r['subjectName'],
                'groups' => $groups,
                'room' => [] !== $rooms ? implode(', ', $rooms) : null,
                'groupCount' => \count($groups),
            ];
        }, $bySlot));
    }

    /**
     * Registers the absence for the periods ticked and lets {@see AbsenceRegistrar} generate a cover per
     * taught period (with its own task document and/or description) and run the equitable assignment.
     * The private reason for the absence is stored once on the {@see \App\Entity\Absence}. Free periods
     * and already-registered ones are reported as skipped. A non-coordinator may only register their own
     * absence (the posted teacher is ignored for them).
     */
    #[Route('/ausencia', name: 'guardia_absence_create', methods: ['POST'])]
    public function createAbsence(Request $request, #[CurrentUser] User $user, UserRepository $users, AcademicYearRepository $years, AbsenceRegistrar $registrar, FileUploader $uploader): Response
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
        // Ticking only the recreo is a legitimate registration on its own: a teacher may be away on a day
        // they teach nothing yet still leave their zone unwatched.
        $missesBreak = $request->request->getBoolean('misses_break');
        if ([] === $slotIndexes && !$missesBreak) {
            $this->addFlash('error', 'Marca al menos una hora en la que falta el profesor.');

            return $this->redirectToRoute('guardia_absence_new', ['date' => $date->format('Y-m-d'), 'teacher' => $teacher->getId()]);
        }

        // One reason for the whole absence (private); a document and/or a description per class.
        $reason = trim((string) $request->request->get('reason'));
        /** @var array<int|string, mixed> $descriptions */
        $descriptions = $request->request->all('description');
        /** @var array<int|string, mixed> $copies */
        $copies = $request->request->all('copies');
        /** @var array<int|string, UploadedFile|null> $files */
        $files = $request->files->all('documents');

        $taskBySlot = [];
        foreach ($slotIndexes as $slotIndex) {
            $description = trim((string) ($descriptions[$slotIndex] ?? ''));
            $document = $files[$slotIndex] ?? null;
            $stored = $document instanceof UploadedFile ? $this->storeTaskDocument($document, $uploader) : null;
            // Las copias de esa clase, si el profesor que falta las sabe (opcional, ver la ficha de la hora).
            $needed = (int) ($copies[$slotIndex] ?? 0);

            $taskBySlot[$slotIndex] = [
                'documentPath' => $stored['path'] ?? null,
                'documentName' => $stored['name'] ?? null,
                'description' => '' !== $description ? $description : null,
                'copies' => $needed > 0 ? $needed : null,
            ];
        }

        $result = $registrar->register($year, $teacher, $date, $slotIndexes, '' !== $reason ? $reason : null, $taskBySlot, $missesBreak);

        // A document uploaded for a period that ended up skipped (free / already registered) is now
        // referenced by no cover: delete it so it does not linger in storage forever.
        foreach ($taskBySlot as $slotIndex => $task) {
            if (null !== $task['documentPath'] && !\in_array($slotIndex, $result->createdSlots, true)) {
                $uploader->remove($task['documentPath']);
            }
        }

        $this->flashRegistration($teacher, $result);

        // When the only consequence was an unwatched recreo, the parte has nothing new to show: land on
        // the gaps screen, which is where somebody has to go looking for a volunteer.
        if ([] === $result->createdSlots && null !== $result->breakGap) {
            return $this->redirectToRoute('break_duty_gap_index');
        }

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
        // The recreo is reported apart from the covers, because it is the opposite of a cover: nothing was
        // assigned and nothing will be. Saying so here is what stops "no se generó ninguna guardia" from
        // reading as "nothing happened" on a day whose only consequence was an unwatched zone.
        $breakNote = null !== $result->breakGap
            ? sprintf(' El recreo de %s se queda sin vigilar: avisado el equipo directivo para buscar un voluntario.', $result->breakGap->getAssignment()->getZone()->getName())
            : '';

        if (0 === $result->createdCount()) {
            if ('' !== $breakNote) {
                $this->addFlash('warning', sprintf('No se generó ninguna guardia para %s (no da clase esas horas o ya estaban en el parte).%s', $teacher->getFullName(), $breakNote));

                return;
            }
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
        $this->addFlash('success', $msg.$breakNote);
    }

    /**
     * Validates and stores one uploaded task document against the shared {@see DocumentUpload} policy.
     * Empty file fields (no file chosen) yield null silently; a rejected upload flashes the reason and
     * yields null, so the rest of the absence still registers without that document.
     *
     * @param UploadedFile $file     the uploaded file
     * @param FileUploader $uploader the private-storage uploader
     *
     * @return array{path: string, name: string}|null the stored path and original filename, or null
     */
    private function storeTaskDocument(UploadedFile $file, FileUploader $uploader): ?array
    {
        if (!DocumentUpload::isPresent($file)) {
            return null;
        }

        $problem = DocumentUpload::problem($file);
        if (null !== $problem) {
            $this->addFlash('warning', $problem.' Se registró sin ese documento.');

            return null;
        }

        return ['path' => $uploader->upload($file, self::TASK_DOCUMENT_SUBDIR), 'name' => DocumentUpload::nameOf($file)];
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
     * Assigns one teacher to one parte line from the assignment sheet in the parte. This is the initial
     * assignment, the manual counterpart of "repartir": no change reason is asked for (unlike
     * {@see updateCover}, which edits an assignment already made and does record one).
     */
    #[Route('/{id}/asignar', name: 'guardia_cover_assign', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function assignCover(GuardiaCover $cover, Request $request, UserRepository $users, GuardiaCoverRepository $covers, AcademicYearRepository $years, GuardiaScheduler $scheduler): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);
        $this->assertCsrf($request, 'guardia_cover_assign'.$cover->getId());

        $teacher = $users->find((int) $request->request->get('guardia'));
        if (!$teacher instanceof User) {
            $this->addFlash('error', 'No se encontró al profesor seleccionado.');

            return $this->backToParte($cover->getDate(), $cover->getSlotIndex());
        }

        $year = $years->findBySchoolYear(SchoolYear::current($cover->getDate()));
        if (!$year instanceof AcademicYear) {
            $this->addFlash('error', sprintf('No hay horario importado para el curso %s.', SchoolYear::current($cover->getDate())));

            return $this->backToParte($cover->getDate(), $cover->getSlotIndex());
        }

        // The scheduler re-checks that the cover is still uncovered and the teacher still available: the
        // form was rendered from a pool that may have changed since (see GuardiaScheduler::assign).
        try {
            $scheduler->assign($year, $cover, $teacher, $covers->findForParte($cover->getDate(), $cover->getSlotIndex()));
            $this->addFlash('success', sprintf('%s cubre %s.', $teacher->getFullName(), $cover->getGroupName() ?? 'la ausencia'));
        } catch (AssignmentRefused $refused) {
            $this->addFlash('error', $refused->getMessage());
        }

        return $this->backToParte($cover->getDate(), $cover->getSlotIndex());
    }

    /**
     * Serves the task document left for a cover's group, as an attachment named after the original
     * upload. Reachable by the guardia teacher assigned to the cover and by the absent teacher (they
     * need / left the work), or by anyone with read access to the guardia area; everyone else is denied.
     * The private reason for the absence is never in this file.
     */
    #[Route('/{id}/tarea', name: 'guardia_task_download', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function downloadTask(GuardiaCover $cover, #[CurrentUser] User $user, FileUploader $uploader): Response
    {
        $path = $cover->getTaskDocumentPath();
        if (null === $path) {
            throw $this->createNotFoundException('Esta guardia no tiene documento de tarea.');
        }

        $isGuardia = $cover->getAssignedGuardia()?->getId() === $user->getId();
        $isAbsent = $cover->getAbsentTeacher()->getId() === $user->getId();
        if (!$isGuardia && !$isAbsent && !$this->isGranted(AreaVoter::READ, Area::GUARDIAS)) {
            throw $this->createAccessDeniedException();
        }

        $absolute = $uploader->absolutePath($path);
        if (!is_file($absolute)) {
            throw $this->createNotFoundException('El documento de tarea ya no está disponible.');
        }

        return $this->file($absolute, $cover->getTaskDocumentName() ?? 'tarea');
    }

    /**
     * The read-only detail of a single guardia: its group/room, day and time, the absent teacher, the
     * task left and how it ended (covered / incident / unassigned). Open to the assigned guardia teacher
     * for THEIR own cover (self-service, no WRITE needed) and to the coordinator (READ). This is where
     * "mis guardias" links each row; coordinators additionally get a link to modify it. The private
     * reason for the absence is shown only to the coordinator, never to the covering guardia.
     */
    #[Route('/{id}/ver', name: 'guardia_cover_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function showCover(GuardiaCover $cover, #[CurrentUser] User $user, ScheduleEntryRepository $schedule, AcademicYearRepository $years): Response
    {
        // A teacher may see the guardia assigned to them; anyone else needs read access to the area.
        $isOwner = $cover->getAssignedGuardia()?->getId() === $user->getId();
        $canManage = $this->isGranted(AreaVoter::READ, Area::GUARDIAS);
        if (!$isOwner && !$canManage) {
            throw $this->createAccessDeniedException();
        }

        $year = $years->findBySchoolYear(SchoolYear::current($cover->getDate()));

        return $this->render('guardia/cover_show.html.twig', [
            'cover' => $cover,
            'slotTimes' => $this->slotTimes($schedule, $year),
            'canEdit' => $this->isGranted(AreaVoter::WRITE, Area::GUARDIAS),
            'canSeeReason' => $canManage,
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
     * "did not happen" flag, with a mandatory explanation recorded in the event log ({@see AuditContext}).
     * When the substitute actually changes, that explanation is sent to both people it affects — the one
     * who takes the guardia over and the one relieved of it.
     */
    #[Route('/{id}/modificar', name: 'guardia_cover_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateCover(GuardiaCover $cover, Request $request, UserRepository $users, EntityManagerInterface $em, GuardiaAssignmentNotifier $notifier, AuditContext $audit, FileUploader $uploader): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);
        $this->assertCsrf($request, 'guardia_cover_update'.$cover->getId());

        $reason = trim((string) $request->request->get('motivo'));
        if ('' === $reason) {
            $this->addFlash('error', 'Explica por qué cambias esta guardia: se lo contamos a los profesores afectados y queda en el histórico.');

            return $this->redirectToRoute('guardia_cover_edit', ['id' => $cover->getId()]);
        }

        $teacherId = $request->request->get('guardia');
        $previous = $cover->getAssignedGuardia();
        $cover->setAssignedGuardia('' !== (string) $teacherId ? $users->find((int) $teacherId) : null);
        $cover->setNotCovered($request->request->getBoolean('not_covered'));
        // setTaskDescription normaliza cadena vacía a null, así que "borrar la descripción" queda soportado.
        $cover->setTaskDescription((string) $request->request->get('task_description'));
        // Copias de la tarea: las deja dichas el profesor ausente al apuntar la falta, o las anota aquí
        // la coordinación cuando la guardia es sobrevenida (setter normaliza 0/vacío a null).
        $cover->setCopiesNeeded((int) $request->request->get('copies_needed'));

        // Task document: replace it with a freshly uploaded one, or drop it if "quitar" was ticked. The
        // old file is deleted only AFTER the change is committed (below), so a failed flush never leaves
        // the cover pointing at a file already gone from disk.
        $oldDocumentPath = null;
        $document = $request->files->get('document');
        if ($document instanceof UploadedFile && \UPLOAD_ERR_NO_FILE !== $document->getError()) {
            $stored = $this->storeTaskDocument($document, $uploader);
            if (null !== $stored) {
                $oldDocumentPath = $cover->getTaskDocumentPath();
                $cover->setTaskDocumentPath($stored['path'])->setTaskDocumentName($stored['name']);
            }
        } elseif ($request->request->getBoolean('remove_document') && null !== $cover->getTaskDocumentPath()) {
            $oldDocumentPath = $cover->getTaskDocumentPath();
            $cover->setTaskDocumentPath(null)->setTaskDocumentName(null);
        }

        // Private reason for the absence (optional): lives on the shared Absence, so editing it here
        // updates it for every period of that day at once — no per-cover copy to drift.
        $cover->getAbsence()->setReason((string) $request->request->get('absence_reason'));

        // The reason rides along into the audit entry this flush produces (see EntityAuditSubscriber).
        $audit->setReason($reason);
        $em->flush();

        // Committed: now it is safe to drop the file the cover no longer references.
        if (null !== $oldDocumentPath) {
            $uploader->remove($oldDocumentPath);
        }

        // Notify only when the substitute actually changes (reselecting the same one does not notify).
        // Everyone the change affects hears about it with the coordinator's explanation: whoever takes
        // the guardia over and whoever is relieved of it — otherwise the relieved teacher would still
        // turn up to a group that is no longer theirs, and the mandatory explanation would die unread
        // in the audit trail.
        $incoming = $cover->getAssignedGuardia();
        $substituteChanged = $incoming !== $previous;
        if ($substituteChanged) {
            // notifyAssigned no hace nada si el hueco se ha dejado vacío, así que no hace falta guardarlo.
            $notifier->notifyAssigned($cover, $reason);
            if (null !== $previous) {
                $notifier->notifyRelieved($cover, $previous, $reason);
            }
        }

        // El acuse dice la verdad, y dice CUÁNTOS avisos han salido: cubrir un hueco vacío (o dejarlo
        // vacío) afecta a una sola persona, así que el plural sería mentira en la mitad de los casos.
        $notified = $substituteChanged ? (int) (null !== $incoming) + (int) (null !== $previous) : 0;
        $this->addFlash('success', match ($notified) {
            2 => 'Guardia modificada. Hemos avisado a los dos profesores y queda en el histórico.',
            1 => 'Guardia modificada. Hemos avisado al profesor afectado y queda en el histórico.',
            default => 'Guardia modificada y registrada en el histórico.',
        });

        return $this->backToParte($cover->getDate(), $cover->getSlotIndex());
    }

    /**
     * Deletes a parte line.
     */
    #[Route('/{id}/borrar', name: 'guardia_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(GuardiaCover $cover, Request $request, EntityManagerInterface $em, GuardiaCoverRepository $covers, FileUploader $uploader): Response
    {
        $this->denyAccessUnlessGranted(AreaVoter::WRITE, Area::GUARDIAS);
        $this->assertCsrf($request, 'guardia_delete'.$cover->getId());

        $date = $cover->getDate();
        $slotIndex = $cover->getSlotIndex();
        $absence = $cover->getAbsence();
        $documentPath = $cover->getTaskDocumentPath();

        // The delete proper: if THIS fails the line is untouched, so the error message is honest.
        try {
            $em->remove($cover);
            $em->flush();
        } catch (\Throwable) {
            $this->addFlash('error', 'No se pudo borrar la línea del parte. Inténtalo de nuevo.');

            return $this->backToParte($date, $slotIndex);
        }

        // The line is gone and committed. Best-effort cleanup — a hiccup here does not undo the delete,
        // so it must not report failure: drop the uploaded document, and the absence too if this was its
        // last period, so neither an orphan file nor an orphan (private) reason lingers.
        if (null !== $documentPath) {
            $uploader->remove($documentPath);
        }
        if (0 === $covers->count(['absence' => $absence])) {
            $em->remove($absence);
            $em->flush();
        }
        $this->addFlash('success', 'Línea del parte eliminada.');

        return $this->backToParte($date, $slotIndex);
    }

    /**
     * Reads the requested date from the query/post ("Y-m-d"), falling back to today on absence or a
     * bad value. Delegates to {@see GuardiaDate}, shared with the rest of the module's controllers.
     *
     * @param Request $request the current request
     *
     * @return \DateTimeImmutable the date to show (time set to midnight)
     */
    private function dateFromRequest(Request $request): \DateTimeImmutable
    {
        return GuardiaDate::fromRequest($request);
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
        return $schedule->slotTimes($year);
    }

}
