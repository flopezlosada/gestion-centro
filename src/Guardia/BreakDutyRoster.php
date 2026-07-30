<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Entity\AcademicYear;
use App\Entity\BreakDutyAssignment;
use App\Entity\BreakZone;
use App\Entity\TimeSlot;
use App\Entity\User;
use App\Enum\Weekday;
use App\Repository\BreakDutyAssignmentRepository;
use App\Repository\BreakZoneRepository;
use App\Repository\TimeSlotRepository;

/**
 * Reads the break duty rota of a course into the two shapes the screens need: the weekday × zone grid
 * the centre draws its recreos on, and the weighted counter that says whether the rota is fair.
 *
 * The counter is the reason this class exists rather than a couple of queries. The centre asked for an
 * equitable count that takes the zone's category into account, and with a rota fixed for the whole
 * course "equitable" is a property of the rota itself, not of anything that happens day by day: it adds
 * up the weight of each teacher's duties, each duty counting once even when it spans both recreos, and
 * hands the totals to {@see GuardiaStatistics::equity()} — the same mean/median/Gini reading the
 * substitution guardias already get, so both rotas are judged by one yardstick.
 *
 * Every read starts from one fetch of the course's rota with its teachers and zones joined, and the
 * aggregation happens here: the rota is a few dozen rows (five weekdays by a handful of zones), so
 * grouping it in PHP is cheaper and clearer than one aggregate query per question.
 */
final class BreakDutyRoster
{
    public function __construct(
        private readonly BreakDutyAssignmentRepository $duties,
        private readonly BreakZoneRepository $zones,
        private readonly TimeSlotRepository $timeSlots,
        private readonly GuardiaStatistics $statistics,
    ) {
    }

    /**
     * Everything the rota screen shows, from a single read: the grid (the recreos of the day with their
     * real times, the zones in use, the duties per weekday and zone, and where it is short of people) and
     * the weighted equity reading.
     *
     * Every weekday × zone cell of the grid exists, so a template can index it without existence checks
     * (Twig raises under strict_variables on a missing key).
     *
     * @param AcademicYear $year the course whose rota to read
     *
     * @return array{
     *     grid: array{
     *         breaks: list<TimeSlot>,
     *         zones: list<BreakZone>,
     *         weekdays: list<Weekday>,
     *         cells: array<int, array<int, list<BreakDutyAssignment>>>,
     *         shortfall: array<int, array<int, int>>,
     *         missing: int
     *     },
     *     equity: array{
     *         rows: list<array{teacher: User, duties: int, load: int, zones: list<string>}>,
     *         equity: array{count: int, mean: float, median: float, min: int, max: int, spread: int, gini: float, label: string}
     *     }
     * } the grid and the equity reading
     */
    public function overview(AcademicYear $year): array
    {
        // One read of the rota for both views: the index screen shows the grid and the equity table
        // together, and asking the database twice for the same few dozen rows is pure waste.
        $duties = $this->duties->findByYear($year);

        return ['grid' => $this->gridFrom($year, $duties), 'equity' => $this->equityFrom($duties)];
    }

    /**
     * The grid alone, for a caller that does not need the equity reading.
     *
     * @param AcademicYear $year the course whose rota to read
     *
     * @return array{
     *     breaks: list<TimeSlot>,
     *     zones: list<BreakZone>,
     *     weekdays: list<Weekday>,
     *     cells: array<int, array<int, list<BreakDutyAssignment>>>,
     *     shortfall: array<int, array<int, int>>,
     *     missing: int
     * } the grid
     */
    public function grid(AcademicYear $year): array
    {
        return $this->gridFrom($year, $this->duties->findByYear($year));
    }

    /**
     * The equity reading alone, for a caller that does not need the grid.
     *
     * @param AcademicYear $year the course whose rota to weigh
     *
     * @return array{
     *     rows: list<array{teacher: User, duties: int, load: int, zones: list<string>}>,
     *     equity: array{count: int, mean: float, median: float, min: int, max: int, spread: int, gini: float, label: string}
     * } the per-teacher totals and the spread
     */
    public function equity(AcademicYear $year): array
    {
        return $this->equityFrom($this->duties->findByYear($year));
    }

    /**
     * Lays the given duties out as the weekday × zone grid.
     *
     * @param AcademicYear          $year   the course the rota belongs to
     * @param BreakDutyAssignment[] $duties the course's duties
     *
     * @return array{
     *     breaks: list<TimeSlot>,
     *     zones: list<BreakZone>,
     *     weekdays: list<Weekday>,
     *     cells: array<int, array<int, list<BreakDutyAssignment>>>,
     *     shortfall: array<int, array<int, int>>,
     *     missing: int
     * } the grid
     */
    private function gridFrom(AcademicYear $year, array $duties): array
    {
        $zones = $this->zones->findActiveOrdered();
        $weekdays = Weekday::schoolWeek();

        $cells = [];
        foreach ($weekdays as $weekday) {
            foreach ($zones as $zone) {
                $cells[$weekday->value][(int) $zone->getId()] = [];
            }
        }

        // An archived zone still holds duties from earlier in the course: its column is gone from the
        // grid, so its rows are skipped here rather than being wedged into a cell that does not exist.
        foreach ($duties as $duty) {
            $zoneId = (int) $duty->getZone()->getId();
            if (!isset($cells[$duty->getWeekday()->value][$zoneId])) {
                continue;
            }
            $cells[$duty->getWeekday()->value][$zoneId][] = $duty;
        }

        $shortfall = [];
        $missing = 0;
        foreach ($weekdays as $weekday) {
            foreach ($zones as $zone) {
                $short = max(0, $zone->getRequiredTeachers() - \count($cells[$weekday->value][(int) $zone->getId()]));
                $shortfall[$weekday->value][(int) $zone->getId()] = $short;
                $missing += $short;
            }
        }

        return [
            'breaks' => array_values($this->timeSlots->findBreaksByYear($year)),
            'zones' => $zones,
            'weekdays' => $weekdays,
            'cells' => $cells,
            'shortfall' => $shortfall,
            'missing' => $missing,
        ];
    }

    /**
     * The equitable reading of the given duties: one row per teacher on the rota, with how many recreo
     * guardias they hold and what those weigh, plus the spread across them.
     *
     * Only teachers who actually have a duty are counted. Averaging over the whole staff would drown the
     * signal — most of the claustro is not on the break rota at all — and the question the centre asks is
     * whether the load is even among those who are.
     *
     * @param BreakDutyAssignment[] $duties the course's duties
     *
     * @return array{
     *     rows: list<array{teacher: User, duties: int, load: int, zones: list<string>}>,
     *     equity: array{count: int, mean: float, median: float, min: int, max: int, spread: int, gini: float, label: string}
     * } the per-teacher totals (heaviest first) and the spread
     */
    private function equityFrom(array $duties): array
    {
        $byTeacher = [];
        foreach ($duties as $duty) {
            $teacherId = (int) $duty->getTeacher()->getId();
            if (!isset($byTeacher[$teacherId])) {
                $byTeacher[$teacherId] = ['teacher' => $duty->getTeacher(), 'duties' => 0, 'load' => 0, 'zones' => []];
            }
            ++$byTeacher[$teacherId]['duties'];
            $byTeacher[$teacherId]['load'] += $duty->load();
            $byTeacher[$teacherId]['zones'][] = $duty->getZone()->getName();
        }

        $rows = array_values(array_map(
            static fn (array $row): array => [
                'teacher' => $row['teacher'],
                'duties' => $row['duties'],
                'load' => $row['load'],
                'zones' => array_values(array_unique($row['zones'])),
            ],
            $byTeacher,
        ));

        // Heaviest first, name as tie-break: the point of the table is who is carrying too much.
        usort($rows, static fn (array $a, array $b): int => [$b['load'], $a['teacher']->getFullName()] <=> [$a['load'], $b['teacher']->getFullName()]);

        return [
            'rows' => $rows,
            'equity' => $this->statistics->equity(array_map(static fn (array $row): int => $row['load'], $rows)),
        ];
    }
}
