<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Entity\AcademicYear;
use App\Entity\ScheduleEntry;
use App\Entity\User;
use App\Enum\ScheduleActivityKind;
use App\Enum\Weekday;
use App\Repository\ScheduleEntryRepository;

/**
 * The manual "horario de guardias" editor: a fallback for when Peñalara imports a teacher's timetable
 * but leaves their guardias out. It reads the marco horario (periods with their times) and the lective
 * cells straight from the imported {@see ScheduleEntry} rows — those are never entered by hand — and
 * lets the equipo directivo mark, per weekday and period, where the teacher is on guardia or
 * collaborator duty. Saving replaces only that teacher's duty cells for the course
 * ({@see ScheduleEntryRepository::replaceDutySlotsForTeacher()}); the lessons stay as imported.
 *
 * Kept pure enough to unit-test: all reads and the write go through the repository, and the two rules
 * that matter live here — a cell is only stamped when it maps to a real period of the marco horario
 * (so its start/end times are known) and never when the teacher already teaches then.
 */
final class GuardiaScheduleEditor
{
    /** The weekdays the grid offers: a Spanish IES runs Monday to Friday. */
    public const WEEKDAYS = [Weekday::MONDAY, Weekday::TUESDAY, Weekday::WEDNESDAY, Weekday::THURSDAY, Weekday::FRIDAY];

    public function __construct(
        private readonly ScheduleEntryRepository $schedule,
    ) {
    }

    /**
     * The editable weekly grid for a teacher in a course: the course's periods (columns) and, for each
     * weekday and period, the current cell — a read-only lesson (shown as context, not editable) or a
     * duty slot to toggle. Every weekday/period cell is pre-populated so the template can index it
     * without existence checks (Twig raises under strict_variables on a missing array key).
     *
     * @param AcademicYear $year    the course whose timetable to read
     * @param User         $teacher the teacher whose grid to build
     *
     * @return array{
     *     slots: list<array{index: int, startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}>,
     *     cells: array<int, array<int, array{lective: bool, value: string, label: string|null, room: string|null}>>
     * } the periods and the cells keyed by weekday value then period index
     */
    public function grid(AcademicYear $year, User $teacher): array
    {
        $slots = $this->schedule->distinctSlots($year);

        // Every weekday × period starts as a free, editable cell.
        $cells = [];
        foreach (self::WEEKDAYS as $weekday) {
            foreach ($slots as $slot) {
                $cells[$weekday->value][$slot['index']] = ['lective' => false, 'value' => '', 'label' => null, 'room' => null];
            }
        }

        // Overlay what the teacher actually has: lessons become read-only context, duty cells preselect.
        foreach ($this->schedule->findByTeacherAndYear($year, $teacher) as $entry) {
            $weekday = $entry->getWeekday()->value;
            $slotIndex = $entry->getSlotIndex();
            if (!isset($cells[$weekday][$slotIndex])) {
                continue; // A period or weekday the grid does not show (e.g. a Saturday cell).
            }

            if (ScheduleActivityKind::LECTIVE === $entry->getKind()) {
                $cells[$weekday][$slotIndex] = [
                    'lective' => true,
                    'value' => '',
                    'label' => $entry->getSubjectName() ?? $entry->getGroupName() ?? 'Clase',
                    'room' => $entry->getRoomName(),
                ];
            } else {
                $cells[$weekday][$slotIndex]['value'] = $entry->getKind()->value;
            }
        }

        return ['slots' => $slots, 'cells' => $cells];
    }

    /**
     * Saves the marked duty cells for a teacher in a course. The matrix is the raw posted grid
     * ({@code cell[weekdayValue][periodIndex] = 'guardia'|'collaborator'|''}); a cell is turned into a
     * {@see ScheduleEntry} only when it names a real duty kind, maps to a period of the course's marco
     * horario (so its times are known) and does not clash with a lesson the teacher already has then.
     * Only the teacher's duty cells are replaced; their imported lessons are left as they are.
     *
     * @param AcademicYear             $year    the course being edited
     * @param User                     $teacher the teacher whose duty cells are saved
     * @param array<array-key, mixed>  $matrix  the posted grid, weekday value → period index → kind value
     *
     * @return int the number of guardia/collaborator cells persisted
     */
    public function save(AcademicYear $year, User $teacher, array $matrix): int
    {
        // The marco horario: a period's times, so a hand-marked duty cell carries the same start/end
        // as the imported lessons at that period.
        $slotTimes = [];
        foreach ($this->schedule->distinctSlots($year) as $slot) {
            $slotTimes[$slot['index']] = ['startsAt' => $slot['startsAt'], 'endsAt' => $slot['endsAt']];
        }

        // Periods the teacher already teaches on each weekday: never overwritable by a guardia.
        $lective = [];
        foreach ($this->schedule->findByTeacherAndYear($year, $teacher) as $entry) {
            if (ScheduleActivityKind::LECTIVE === $entry->getKind()) {
                $lective[$entry->getWeekday()->value][$entry->getSlotIndex()] = true;
            }
        }

        $entries = [];
        foreach ($matrix as $weekdayValue => $row) {
            $weekday = Weekday::tryFrom((int) $weekdayValue);
            if (null === $weekday || !\is_array($row)) {
                continue;
            }
            foreach ($row as $slotIndex => $value) {
                if (!\is_string($value)) {
                    continue;
                }
                $slotIndex = (int) $slotIndex;
                $kind = match ($value) {
                    'guardia' => ScheduleActivityKind::GUARDIA,
                    'collaborator' => ScheduleActivityKind::COLLABORATOR,
                    default => null,
                };
                // Ignore free cells, unknown periods and slots where the teacher is teaching.
                if (null === $kind || !isset($slotTimes[$slotIndex]) || isset($lective[$weekday->value][$slotIndex])) {
                    continue;
                }

                $entries[] = (new ScheduleEntry())
                    ->setAcademicYear($year)
                    ->setTeacher($teacher)
                    ->setWeekday($weekday)
                    ->setSlotIndex($slotIndex)
                    ->setStartsAt($slotTimes[$slotIndex]['startsAt'])
                    ->setEndsAt($slotTimes[$slotIndex]['endsAt'])
                    ->setKind($kind);
            }
        }

        $this->schedule->replaceDutySlotsForTeacher($year, $teacher, $entries);

        return \count($entries);
    }
}
