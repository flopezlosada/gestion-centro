<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Entity\Absence;
use App\Enum\Weekday;
use App\Repository\AbsenceRepository;
use App\Repository\AcademicYearRepository;
use App\Repository\ScheduleEntryRepository;
use App\Service\AppSettings;
use App\Util\CalendarDate;
use App\Util\SchoolYear;

/**
 * The absences as the administrative staff read them (on screen and in the weekly PDF for RAICES): each
 * one with the times of its periods, and never anything older than the plazo the centre keeps them for,
 * even before the daily purge has run.
 *
 * @phpstan-type Row array{absence: Absence, slotTimes: array<int, array{startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}>}
 * @phpstan-type Day array{date: \DateTimeImmutable, weekday: Weekday, beforeKept: bool, rows: list<Row>}
 */
final readonly class AbsenceListing
{
    /** The school days of a week, Monday first. */
    private const int SCHOOL_DAYS = 5;

    /**
     * @param AbsenceRepository       $absences where the absences are
     * @param AcademicYearRepository  $years    the courses, to find each absence's timetable
     * @param ScheduleEntryRepository $schedule the timetables, for the times of the periods
     * @param AppSettings             $settings where the centre's plazo is kept
     */
    public function __construct(
        private AbsenceRepository $absences,
        private AcademicYearRepository $years,
        private ScheduleEntryRepository $schedule,
        private AppSettings $settings,
    ) {
    }

    /**
     * Every absence still kept, the most recent day first and, within a day, by name.
     *
     * @param \DateTimeImmutable $today the day the plazo counts back from
     *
     * @return list<Row> one row per absence
     */
    public function kept(\DateTimeImmutable $today): array
    {
        return $this->withSlotTimes($this->absences->findFrom($this->keptFrom($today)));
    }

    /**
     * The school week a day falls in, Monday to Friday, every day listed even when nobody was away: «nobody»
     * is also an answer when going through RAICES. The days older than the plazo come out flagged and empty,
     * so the PDF says they were deleted rather than becoming a way round the purge.
     *
     * @param \DateTimeImmutable $anyDay any day of the week wanted
     * @param \DateTimeImmutable $today  the day the plazo counts back from
     *
     * @return array{monday: \DateTimeImmutable, friday: \DateTimeImmutable, days: list<Day>} the week
     */
    public function week(\DateTimeImmutable $anyDay, \DateTimeImmutable $today): array
    {
        $monday = CalendarDate::mondayOf($anyDay);
        $saturday = $monday->modify(\sprintf('+%d days', self::SCHOOL_DAYS));
        $keptFrom = $this->keptFrom($today);

        $byDay = [];
        foreach ($this->withSlotTimes($this->absences->findFrom(max($monday, $keptFrom), $saturday)) as $row) {
            // Within a day the repository's order (by name) holds.
            $byDay[$row['absence']->getDate()->format('Y-m-d')][] = $row;
        }

        $days = [];
        for ($day = $monday; $day < $saturday; $day = $day->modify('+1 day')) {
            $days[] = [
                'date' => $day,
                'weekday' => Weekday::from((int) $day->format('N')),
                'beforeKept' => $day < $keptFrom,
                'rows' => $byDay[$day->format('Y-m-d')] ?? [],
            ];
        }

        return ['monday' => $monday, 'friday' => $saturday->modify('-1 day'), 'days' => $days];
    }

    /**
     * The oldest day still kept.
     *
     * @param \DateTimeImmutable $today the day the plazo counts back from
     *
     * @return \DateTimeImmutable midnight of that day
     */
    public function keptFrom(\DateTimeImmutable $today): \DateTimeImmutable
    {
        return $this->settings->getAbsenceRetention()->keptFrom($today);
    }

    /**
     * Pairs each absence with the times of the periods of its course, to show «08:30–09:25» instead of
     * «1ª». The plazo can straddle two courses (late September looks back into June), and each has its own
     * timetable: the times are read once per course, not once per row.
     *
     * @param list<Absence> $absences the absences
     *
     * @return list<Row> one row per absence, in the same order
     */
    private function withSlotTimes(array $absences): array
    {
        $timesByCourse = [];

        return array_map(function (Absence $absence) use (&$timesByCourse): array {
            $course = SchoolYear::current($absence->getDate());

            return [
                'absence' => $absence,
                'slotTimes' => $timesByCourse[$course] ??= $this->schedule->slotTimes($this->years->findBySchoolYear($course)),
            ];
        }, $absences);
    }
}
