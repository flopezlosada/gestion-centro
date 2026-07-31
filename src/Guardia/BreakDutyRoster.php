<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Entity\AcademicYear;
use App\Entity\BreakDutyAssignment;
use App\Entity\BreakZone;
use App\Entity\TimeSlot;
use App\Entity\User;
use App\Enum\BreakDutySource;
use App\Enum\BreakPeriod;
use App\Enum\Weekday;
use App\Repository\BreakDutyAssignmentRepository;
use App\Repository\UserRepository;
use App\Repository\BreakZoneRepository;
use App\Repository\TimeSlotRepository;

/**
 * Reads the break duty rota of a course into the two shapes the screens need: the recreo × weekday ×
 * zone grid the centre draws its recreos on, and the weighted counter that says whether the rota is fair.
 *
 * The counter is the reason this class exists rather than a couple of queries. The centre asked for an
 * equitable count that takes the zone's category into account, and with a rota fixed for the whole
 * course "equitable" is a property of the rota itself, not of anything that happens day by day: it adds
 * up the weight of each teacher's PLACES and hands the totals to {@see GuardiaStatistics::equity()} —
 * the same mean/median/Gini reading the substitution guardias already get, so both rotas are judged by
 * one yardstick.
 *
 * It is also where the centre's counting rule lives, now that it is arithmetic rather than structure: a
 * guardia is one long recreo plus one short one, on any days, so somebody's guardias are
 * {@code min(long, short)} and the remainder are halves waiting for a partner.
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
        private readonly BreakDutyDemand $demand,
        // Solo para dibujar una PROPUESTA: sus plazas vienen con ids de docente, y hay que resolverlos a
        // personas para poder pintar la misma rejilla que la del cuadrante guardado.
        private readonly UserRepository $users,
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
     *         periods: list<BreakPeriod>,
     *         cells: array<string, array<int, array<int, list<BreakDutyAssignment>>>>,
     *         shortfall: array<string, array<int, array<int, int>>>,
     *         surplus: array<string, array<int, array<int, int>>>,
     *         required: array<string, array<int, array<int, int>>>,
     *         zoneNeeds: array<int, array{min: int, max: int}>,
     *         total: int,
     *         manualCount: int,
     *         extra: int,
     *         missing: int
     *     },
     *     equity: array{
     *         rows: list<array{teacher: User, guardias: int, halves: int, places: int, load: int, zones: list<string>}>,
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
     *     periods: list<BreakPeriod>,
     *     cells: array<string, array<int, array<int, list<BreakDutyAssignment>>>>,
     *     shortfall: array<string, array<int, array<int, int>>>,
     *     surplus: array<string, array<int, array<int, int>>>,
     *     required: array<string, array<int, array<int, int>>>,
     *     zoneNeeds: array<int, array{min: int, max: int}>,
     *     total: int,
     *     manualCount: int,
     *     extra: int,
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
     *     rows: list<array{teacher: User, guardias: int, halves: int, places: int, load: int, zones: list<string>}>,
     *     equity: array{count: int, mean: float, median: float, min: int, max: int, spread: int, gini: float, label: string}
     * } the per-teacher totals and the spread
     */
    public function equity(AcademicYear $year): array
    {
        return $this->equityFrom($this->duties->findByYear($year));
    }

    /**
     * Lays the given places out as one recreo × weekday × zone grid.
     *
     * Three dimensions rather than two, because a place now belongs to a specific recreo: the same
     * teacher can be in the patio at the long one and the biblioteca at the short one, and a grid that
     * only knew about weekdays would have to pile both into one cell.
     *
     * Every cell exists even when empty, so a template can index it without existence checks (Twig
     * raises under strict_variables on a missing key).
     *
     * @param AcademicYear          $year   the course the rota belongs to
     * @param BreakDutyAssignment[] $duties the course's places
     *
     * @return array{
     *     breaks: list<TimeSlot>,
     *     zones: list<BreakZone>,
     *     weekdays: list<Weekday>,
     *     periods: list<BreakPeriod>,
     *     cells: array<string, array<int, array<int, list<BreakDutyAssignment>>>>,
     *     shortfall: array<string, array<int, array<int, int>>>,
     *     surplus: array<string, array<int, array<int, int>>>,
     *     required: array<string, array<int, array<int, int>>>,
     *     zoneNeeds: array<int, array{min: int, max: int}>,
     *     total: int,
     *     manualCount: int,
     *     extra: int,
     *     missing: int
     * } the grid
     */
    private function gridFrom(AcademicYear $year, array $duties): array
    {
        $zones = $this->zones->findActiveOrdered();
        $weekdays = Weekday::schoolWeek();
        $periods = BreakPeriod::inDayOrder();

        $cells = [];
        foreach ($periods as $period) {
            foreach ($weekdays as $weekday) {
                foreach ($zones as $zone) {
                    $cells[$period->value][$weekday->value][(int) $zone->getId()] = [];
                }
            }
        }

        // An archived zone still holds places from earlier in the course: its column is gone from the
        // grid, so its rows are skipped here rather than being wedged into a cell that does not exist.
        foreach ($duties as $duty) {
            $zoneId = (int) $duty->getZone()->getId();
            if (!isset($cells[$duty->getPeriod()->value][$duty->getWeekday()->value][$zoneId])) {
                continue;
            }
            $cells[$duty->getPeriod()->value][$duty->getWeekday()->value][$zoneId][] = $duty;
        }

        $shortfall = [];
        // Lo que SOBRA se cuenta igual que lo que falta. Una celda con más gente de la que la zona pide no
        // es "completa": está gastando plazas de recreo que hacen falta otro día, y el cuadrante se
        // declaraba COMPLETO sin decir nada. También se guarda lo que pide cada celda, porque el peso de la
        // zona (que es su exigencia para el reparto) se estaba leyendo como si fuera el número de plazas.
        $surplus = [];
        $required = [];
        // Cuántos profesores pide cada ZONA, para poder decirlo en su fila: es lo primero que se pregunta
        // quien mira el cuadrante ("¿cuántos tiene que haber en el patio?"). Se guarda el mínimo y el máximo
        // porque la demanda puede variar por día y por recreo; si coinciden es un número y si no, un rango.
        $zoneNeeds = [];
        $missing = 0;
        $extra = 0;
        foreach ($periods as $period) {
            foreach ($weekdays as $weekday) {
                foreach ($zones as $zone) {
                    $zoneId = (int) $zone->getId();
                    $needed = $this->demand->required($zone, $weekday, $period);
                    $have = \count($cells[$period->value][$weekday->value][$zoneId]);
                    $required[$period->value][$weekday->value][$zoneId] = $needed;
                    $zoneNeeds[$zoneId]['min'] = min($zoneNeeds[$zoneId]['min'] ?? $needed, $needed);
                    $zoneNeeds[$zoneId]['max'] = max($zoneNeeds[$zoneId]['max'] ?? $needed, $needed);
                    $shortfall[$period->value][$weekday->value][$zoneId] = max(0, $needed - $have);
                    $surplus[$period->value][$weekday->value][$zoneId] = max(0, $have - $needed);
                    $missing += max(0, $needed - $have);
                    $extra += max(0, $have - $needed);
                }
            }
        }

        return [
            'breaks' => array_values($this->timeSlots->findBreaksByYear($year)),
            'zones' => $zones,
            'weekdays' => $weekdays,
            'periods' => $periods,
            'cells' => $cells,
            'shortfall' => $shortfall,
            'surplus' => $surplus,
            'required' => $required,
            'zoneNeeds' => $zoneNeeds,
            // Para el botón de vaciar: cuántas plazas hay y cuántas puso una persona (las que nadie puede
            // recuperar sola si se borran).
            'total' => \count($duties),
            'manualCount' => \count(array_filter($duties, static fn (BreakDutyAssignment $d): bool => BreakDutySource::MANUAL === $d->getSource())),
            'missing' => $missing,
            'extra' => $extra,
        ];
    }

    /**
     * The same grid, but drawn from a PROPOSAL instead of from what is stored — so the draft can actually be
     * reviewed before publishing it.
     *
     * It existed only as headline figures ("30 guardias completas, 0 plazas sin cubrir") while the table
     * underneath kept showing the saved rota, which on an empty course meant the whole grid at 0/2: numbers
     * to trust blindly and nothing to validate. The centre asked to "validar la propuesta y/o modificarla",
     * and you cannot validate what you cannot see.
     *
     * The proposal carries plain ids ({@see BreakRotaProposal}), so they are turned into unsaved
     * {@see BreakDutyAssignment} objects: throwaway objects, never persisted, only so the grid can be built
     * by the SAME code that draws the real one — two drawings of the same thing would drift apart.
     *
     * @param AcademicYear                                                                        $year   the course
     * @param list<array{weekday: int, period: string, zoneId: int, teacherId: int, fixed: bool}> $places the proposed places
     *
     * @return array<string, mixed> the grid, in the same shape {@see overview()} returns
     */
    public function gridFromProposal(AcademicYear $year, array $places): array
    {
        $teachers = [];
        foreach ($this->users->findActive() as $user) {
            $teachers[(int) $user->getId()] = $user;
        }
        $zones = [];
        foreach ($this->zones->findActiveOrdered() as $zone) {
            $zones[(int) $zone->getId()] = $zone;
        }

        $duties = [];
        foreach ($places as $place) {
            $teacher = $teachers[$place['teacherId']] ?? null;
            $zone = $zones[$place['zoneId']] ?? null;
            if (null === $teacher || null === $zone) {
                continue; // una zona archivada o alguien dado de baja entre proponer y pintar
            }
            $duties[] = (new BreakDutyAssignment())
                ->setAcademicYear($year)
                ->setTeacher($teacher)
                ->setZone($zone)
                ->setWeekday(Weekday::from($place['weekday']))
                ->setPeriod(BreakPeriod::from($place['period']))
                ->setSource($place['fixed'] ? BreakDutySource::MANUAL : BreakDutySource::ENGINE);
        }

        return $this->gridFrom($year, $duties);
    }

    /**
     * The equitable reading of the given places: one row per teacher on the rota, how many guardias that
     * makes, what they weigh, and the spread across everybody.
     *
     * **A guardia is a long recreo plus a short one** (the centre's rule, and they may be on different
     * days), so the count is {@code min(long, short)} and whatever is left over is a HALF: a place with
     * no partner. Halves are reported rather than rounded away — somebody carrying two long recreos and
     * no short one has done as much work as somebody with one of each, and only the report can say so.
     *
     * Only teachers who actually hold a place are counted. Averaging over the whole staff would drown
     * the signal — most of the claustro is not on the break rota at all — and the question the centre
     * asks is whether the load is even among those who are.
     *
     * @param BreakDutyAssignment[] $duties the course's places
     *
     * @return array{
     *     rows: list<array{teacher: User, guardias: int, halves: int, places: int, load: int, zones: list<string>}>,
     *     equity: array{count: int, mean: float, median: float, min: int, max: int, spread: int, gini: float, label: string}
     * } the per-teacher totals (heaviest first) and the spread
     */
    private function equityFrom(array $duties): array
    {
        $byTeacher = [];
        foreach ($duties as $duty) {
            $teacherId = (int) $duty->getTeacher()->getId();
            if (!isset($byTeacher[$teacherId])) {
                $byTeacher[$teacherId] = ['teacher' => $duty->getTeacher(), 'long' => 0, 'short' => 0, 'load' => 0, 'zones' => []];
            }
            $key = BreakPeriod::FIRST === $duty->getPeriod() ? 'long' : 'short';
            ++$byTeacher[$teacherId][$key];
            $byTeacher[$teacherId]['load'] += $duty->load();
            $byTeacher[$teacherId]['zones'][] = $duty->getZone()->getName();
        }

        $rows = array_values(array_map(
            static fn (array $row): array => [
                'teacher' => $row['teacher'],
                'guardias' => min($row['long'], $row['short']),
                'halves' => abs($row['long'] - $row['short']),
                'places' => $row['long'] + $row['short'],
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
