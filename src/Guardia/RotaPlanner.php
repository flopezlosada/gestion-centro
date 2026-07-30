<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Entity\AcademicYear;
use App\Entity\ScheduleEntry;
use App\Entity\User;
use App\Enum\ScheduleActivityKind;
use App\Enum\ScheduleEntrySource;
use App\Enum\TimeSlotKind;
use App\Enum\Weekday;
use App\Repository\GuardiaQuotaRepository;
use App\Repository\ScheduleEntryRepository;
use App\Repository\TimeSlotRepository;

/**
 * Puts the rota engine to work against the database: gathers what {@see RotaProposer} needs, and writes
 * an approved proposal back into the timetable.
 *
 * The split is deliberate. The engine is pure and knows nothing about Doctrine, so it can be reasoned
 * about and tested on paper; everything that has to touch the database lives here. It is the same shape
 * {@see GuardiaScheduler} has around {@see GuardiaAssigner} for the daily parte.
 *
 * What it does NOT do is decide anything: the proposal it returns is a draft nobody has seen yet, and
 * nothing reaches the timetable until {@see publish()} is called from a screen where a human pressed
 * approve.
 */
final class RotaPlanner
{
    public function __construct(
        private readonly ScheduleEntryRepository $schedule,
        private readonly TimeSlotRepository $timeSlots,
        private readonly GuardiaQuotaRepository $quotas,
        private readonly RotaProposer $proposer,
    ) {
    }

    /**
     * Draws up a proposal for a course.
     *
     * Cells a person marked by hand are passed in as fixed, so re-proposing after somebody retouched the
     * rota keeps their change and only redraws what the engine itself had placed.
     *
     * @param AcademicYear $year the course to draw up
     *
     * @return RotaProposal the draft and its report
     */
    public function propose(AcademicYear $year): RotaProposal
    {
        return $this->proposer->propose(
            $this->lectiveSlots($year),
            $this->candidates($year),
            $this->fixedPlaces($year),
        );
    }

    /**
     * Writes an approved proposal into the timetable, replacing whatever the engine had placed before and
     * leaving hand-marked and imported cells alone.
     *
     * Places already fixed are skipped. They are in the proposal because the grid has to show the whole
     * week, but they already exist in the timetable as MANUAL rows: writing them again would put the same
     * teacher in the same period twice, once under each source.
     *
     * @param AcademicYear                                                                     $year       the course
     * @param list<array{weekday: int, slot: int, teacherId: int, kind: string, fixed?: bool}> $placements the approved rota
     *
     * @return int how many duty cells were written
     */
    public function publish(AcademicYear $year, array $placements): int
    {
        $times = $this->slotTimes($year);
        $teachers = $this->teachersById($year);

        $entries = [];
        foreach ($placements as $place) {
            if ($place['fixed'] ?? false) {
                continue;
            }
            $teacher = $teachers[$place['teacherId']] ?? null;
            $when = $times[$place['slot']] ?? null;
            $kind = ScheduleActivityKind::tryFrom($place['kind']);

            // A placement naming somebody outside the course, a period outside the frame or a kind that
            // is not a duty is dropped rather than trusted: the form is a proposal, not an instruction.
            if (!$teacher instanceof User || null === $when || null === $kind
                || !\in_array($kind, [ScheduleActivityKind::GUARDIA, ScheduleActivityKind::COLLABORATOR], true)) {
                continue;
            }

            $entries[] = (new ScheduleEntry())
                ->setAcademicYear($year)
                ->setTeacher($teacher)
                ->setWeekday(Weekday::from($place['weekday']))
                ->setSlotIndex($place['slot'])
                ->setStartsAt($when[0])
                ->setEndsAt($when[1])
                ->setKind($kind)
                ->setSource(ScheduleEntrySource::ENGINE);
        }

        $this->schedule->replaceEngineDutyCells($year, $entries);

        return \count($entries);
    }

    /**
     * The teachers the engine may place, with their quota and their teaching week.
     *
     * Everybody with a timetable gets a candidate, quota included as zero when nothing has been typed:
     * the engine drops those itself, and building the list this way means the count of candidates always
     * matches the count of rows on the quota screen.
     *
     * @param AcademicYear $year the course
     *
     * @return list<RotaCandidate> the candidates
     */
    public function candidates(AcademicYear $year): array
    {
        $busy = $this->schedule->lectiveSlotsByTeacher($year);
        $quotas = $this->quotas->findByYearKeyedByTeacher($year);

        $candidates = [];
        foreach ($this->schedule->teachersWithEntries($year) as $teacher) {
            $id = (int) $teacher->getId();
            $candidates[] = new RotaCandidate(
                $id,
                $teacher->getFullName(),
                isset($quotas[$id]) ? $quotas[$id]->getLectiveDuties() : 0,
                $busy[$id] ?? [],
            );
        }

        return $candidates;
    }

    /**
     * The teaching periods of the course's day.
     *
     * Falls back to the periods that hold activity when the marco horario has not been imported yet,
     * the same way the quota screen does — a course loaded before {@see \App\Entity\TimeSlot} existed
     * has a full timetable and an empty frame.
     *
     * @param AcademicYear $year the course
     *
     * @return list<int> the teaching period indexes
     */
    public function lectiveSlots(AcademicYear $year): array
    {
        $frame = $this->timeSlots->findLectiveByYear($year);
        if ([] !== $frame) {
            return array_values(array_map(static fn ($slot): int => $slot->getSlotIndex(), $frame));
        }

        return array_map(static fn (array $slot): int => $slot['index'], $this->schedule->distinctSlots($year));
    }

    /**
     * The duty cells the engine must honour: everything it did not place itself.
     *
     * That is both the ones somebody marked by hand and the ones that came in the Peñalara export —
     * where a centre's timetable already carries guardias, those are the official rota and the engine
     * has no business moving them. Leaving them out was a real bug: they are not in the proposal, and
     * publishing only replaces the engine's own cells, so the same teacher could end up on guardia
     * twice in one period, once under each source.
     *
     * Only the engine's own cells are left out, because redrawing those is exactly what a new proposal
     * is for.
     *
     * @param AcademicYear $year the course
     *
     * @return list<array{weekday: int, slot: int, teacherId: int, kind: ScheduleActivityKind}> the fixed places
     */
    private function fixedPlaces(AcademicYear $year): array
    {
        $fixed = [];
        foreach ($this->schedule->findDutyCells($year) as $cell) {
            if (ScheduleEntrySource::ENGINE === $cell->getSource()) {
                continue;
            }
            $fixed[] = [
                'weekday' => $cell->getWeekday()->value,
                'slot' => $cell->getSlotIndex(),
                'teacherId' => (int) $cell->getTeacher()->getId(),
                'kind' => $cell->getKind(),
            ];
        }

        return $fixed;
    }

    /**
     * Start and end time of each period of the course's day, so a written cell carries real hours.
     *
     * @param AcademicYear $year the course
     *
     * @return array<int, array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> period index → [start, end]
     */
    private function slotTimes(AcademicYear $year): array
    {
        $times = [];
        foreach ($this->timeSlots->findByYear($year) as $slot) {
            if (TimeSlotKind::LECTIVE === $slot->getKind()) {
                $times[$slot->getSlotIndex()] = [$slot->getStartsAt(), $slot->getEndsAt()];
            }
        }

        if ([] !== $times) {
            return $times;
        }

        // No frame imported: take the hours from the timetable itself, which is where the quota screen
        // gets its period count from too.
        foreach ($this->schedule->distinctSlots($year) as $slot) {
            $times[$slot['index']] = [$slot['startsAt'], $slot['endsAt']];
        }

        return $times;
    }

    /**
     * The course's teachers keyed by id, so publishing does not hit the database once per placement.
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
