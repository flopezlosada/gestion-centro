<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Entity\AcademicYear;
use App\Entity\BreakDutyAssignment;
use App\Entity\BreakZone;
use App\Entity\User;
use App\Enum\BreakDutySource;
use App\Enum\BreakPeriod;
use App\Enum\Weekday;
use App\Repository\BreakDutyAssignmentRepository;
use App\Repository\BreakZoneRepository;
use App\Repository\GuardiaQuotaRepository;
use App\Repository\ScheduleEntryRepository;

/**
 * Puts the break rota engine to work against the database: gathers what {@see BreakRotaProposer} needs,
 * and writes an approved proposal back into the rota.
 *
 * Same split as {@see RotaPlanner} for the teaching rota — the engine stays pure and everything that
 * touches Doctrine lives here — and the same promise: nothing reaches the rota until a human presses
 * approve.
 */
final class BreakRotaPlanner
{
    public function __construct(
        private readonly BreakDutyAssignmentRepository $duties,
        private readonly BreakZoneRepository $zones,
        private readonly BreakDutyDemand $demand,
        private readonly GuardiaQuotaRepository $quotas,
        private readonly ScheduleEntryRepository $schedule,
        private readonly BreakRotaProposer $proposer,
    ) {
    }

    /**
     * Draws up a proposal for a course.
     *
     * @param AcademicYear $year the course to draw up
     *
     * @return BreakRotaProposal the draft and its report
     */
    public function propose(AcademicYear $year): BreakRotaProposal
    {
        return $this->proposer->propose($this->places(), $this->candidates($year), $this->fixedPlaces($year));
    }

    /**
     * Writes an approved proposal into the rota, replacing whatever the engine had placed before and
     * leaving hand-added places alone.
     *
     * Places already fixed are skipped: they are in the proposal because the grid shows the whole week,
     * but they already exist as MANUAL rows, and writing them again would break the unique key.
     *
     * @param AcademicYear                                                                    $year   the course
     * @param list<array{weekday: int, period: string, zoneId: int, teacherId: int, fixed?: bool}> $places the approved rota
     *
     * @return int how many places were written
     */
    public function publish(AcademicYear $year, array $places): int
    {
        $teachers = $this->teachersById($year);
        $zones = [];
        foreach ($this->zones->findActiveOrdered() as $zone) {
            $zones[(int) $zone->getId()] = $zone;
        }

        $rows = [];
        foreach ($places as $place) {
            if ($place['fixed'] ?? false) {
                continue;
            }
            $teacher = $teachers[$place['teacherId']] ?? null;
            $zone = $zones[$place['zoneId']] ?? null;
            $period = BreakPeriod::tryFrom($place['period']);

            // A place naming somebody outside the course, an archived zone or a recreo that does not
            // exist is dropped rather than trusted: the form is a proposal, not an instruction.
            if (!$teacher instanceof User || !$zone instanceof BreakZone || null === $period) {
                continue;
            }

            $rows[] = (new BreakDutyAssignment())
                ->setAcademicYear($year)
                ->setTeacher($teacher)
                ->setWeekday(Weekday::from($place['weekday']))
                ->setZone($zone)
                ->setPeriod($period)
                ->setSource(BreakDutySource::ENGINE);
        }

        $this->duties->replaceEnginePlaces($year, $rows);

        return \count($rows);
    }

    /**
     * Every place the week needs: one entry per person, per zone, per weekday, per recreo.
     *
     * Expanded from the demand rather than counted, because that is the shape the engine works in — a
     * zone needing two people is two places to hand out, not one place with a number on it.
     *
     * @return list<array{weekday: int, period: BreakPeriod, zoneId: int, weight: int}> the week's demand
     */
    public function places(): array
    {
        $places = [];
        foreach (BreakPeriod::inDayOrder() as $period) {
            foreach (Weekday::schoolWeek() as $weekday) {
                foreach ($this->zones->findActiveOrdered() as $zone) {
                    $needed = $this->demand->required($zone, $weekday, $period);
                    for ($i = 0; $i < $needed; ++$i) {
                        $places[] = [
                            'weekday' => $weekday->value,
                            'period' => $period,
                            'zoneId' => (int) $zone->getId(),
                            'weight' => $zone->getWeight(),
                        ];
                    }
                }
            }
        }

        return $places;
    }

    /**
     * The teachers the engine may place, with the recreo quota the equipo directivo typed in.
     *
     * Everybody with a timetable gets a candidate, quota included as zero when nothing has been typed:
     * the engine drops those itself, and the list then matches the quota screen row for row.
     *
     * @param AcademicYear $year the course
     *
     * @return list<BreakRotaCandidate> the candidates
     */
    public function candidates(AcademicYear $year): array
    {
        $quotas = $this->quotas->findByYearKeyedByTeacher($year);

        $candidates = [];
        foreach ($this->schedule->teachersWithEntries($year) as $teacher) {
            $id = (int) $teacher->getId();
            $candidates[] = new BreakRotaCandidate(
                $id,
                $teacher->getFullName(),
                isset($quotas[$id]) ? $quotas[$id]->getBreakDuties() : 0,
            );
        }

        return $candidates;
    }

    /**
     * The places somebody added by hand, which the engine must honour — the patios dirigidos the equipo
     * directivo organises by day, above all.
     *
     * @param AcademicYear $year the course
     *
     * @return list<array{weekday: int, period: BreakPeriod, zoneId: int, teacherId: int}> the fixed places
     */
    private function fixedPlaces(AcademicYear $year): array
    {
        $fixed = [];
        foreach ($this->duties->findByYear($year) as $place) {
            if (BreakDutySource::ENGINE === $place->getSource()) {
                continue;
            }
            $fixed[] = [
                'weekday' => $place->getWeekday()->value,
                'period' => $place->getPeriod(),
                'zoneId' => (int) $place->getZone()->getId(),
                'teacherId' => (int) $place->getTeacher()->getId(),
            ];
        }

        return $fixed;
    }

    /**
     * The course's teachers keyed by id, so publishing does not hit the database once per place.
     *
     * @param AcademicYear $year the course
     *
     * @return array<int, User> teacher id → teacher
     */
    private function teachersById(AcademicYear $year): array
    {
        $byId = [];
        foreach ($this->schedule->teachersWithEntries($year) as $teacher) {
            $byId[(int) $teacher->getId()] = $teacher;
        }

        return $byId;
    }
}
