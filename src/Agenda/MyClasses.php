<?php

declare(strict_types=1);

namespace App\Agenda;

use App\Entity\AcademicYear;
use App\Entity\User;
use App\Repository\AcademicYearRepository;
use App\Repository\TimeSlotRepository;
use App\Service\SchoolCalendar;
use App\Space\EffectiveTimetable;
use App\Util\CalendarDate;
use App\Util\SchoolYear;

/**
 * The classes a teacher gives on a day, one per period ({@see ClassSession}), as they really happen:
 * the imported timetable with the approved space plans applied ({@see EffectiveTimetable}: a relocated lesson shows its new room, and exam week
 * drops the lessons it replaces), dated and timed with the course's marco horario.
 *
 * It did not exist before: the timetable was in the database from the Peñalara import, but only the
 * guardia machinery read it, and no teacher could see their own classes anywhere in the application.
 * It is also the base of planning each class, which hangs from these sessions.
 *
 * Only teaching days of an existing course have classes: a weekend, a registered non-teaching day, or a
 * day before the course starts or after it ends has none.
 */
final class MyClasses
{
    /** The first look for the same class: a week and a day, where the next one with a group almost always is. */
    private const FIRST_WINDOW_DAYS = 8;
    /** Each further look — past a two-week holiday. */
    private const WINDOW_DAYS = 28;

    public function __construct(
        private readonly EffectiveTimetable $timetable,
        private readonly AcademicYearRepository $years,
        private readonly TimeSlotRepository $timeSlots,
        private readonly SchoolCalendar $calendar,
    ) {
    }

    /**
     * A teacher's classes on one day, earliest first.
     *
     * @param User               $teacher the teacher
     * @param \DateTimeImmutable $day     the day (its time part is ignored)
     *
     * @return list<ClassSession> the day's classes
     */
    public function on(User $teacher, \DateTimeImmutable $day): array
    {
        return $this->between($teacher, $day, $day)[$day->format('Y-m-d')] ?? [];
    }

    /**
     * A teacher's classes day by day over a range, for the week view. Days without classes are absent.
     * The course and its frame are read once per course touched, not once per day.
     *
     * @param User               $teacher the teacher
     * @param \DateTimeImmutable $from    the first day (inclusive)
     * @param \DateTimeImmutable $to      the last day (inclusive)
     *
     * @return array<string, list<ClassSession>> "Y-m-d" → that day's classes, earliest first
     */
    public function between(User $teacher, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var array<string, array{year: AcademicYear|null, frame: array<int, array{startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}>}> $courses */
        $courses = [];
        $byDay = [];

        for ($day = $from->setTime(0, 0); $day <= $to; $day = $day->modify('+1 day')) {
            $schoolYear = SchoolYear::current($day);
            if (!isset($courses[$schoolYear])) {
                $year = $this->years->findBySchoolYear($schoolYear);
                $courses[$schoolYear] = [
                    'year' => $year,
                    'frame' => null !== $year ? $this->timeSlots->lectiveTimesWithFallback($year)['slots'] : [],
                ];
            }
            ['year' => $year, 'frame' => $frame] = $courses[$schoolYear];

            if (null === $year || !$this->isTeachingDay($year, $day)) {
                continue;
            }

            $ordinals = array_flip(array_keys($frame));
            $sessions = [];
            foreach ($this->timetable->forTeacherOn($year, $teacher, $day) as $slotIndex => $lessons) {
                if ([] === $lessons) {
                    continue;
                }
                $times = $frame[$slotIndex] ?? null;
                $sessions[] = new ClassSession(
                    $lessons,
                    $slotIndex,
                    isset($ordinals[$slotIndex]) ? $ordinals[$slotIndex] + 1 : $slotIndex + 1,
                    null !== $times ? CalendarDate::at($day, $times['startsAt']) : null,
                    null !== $times ? CalendarDate::at($day, $times['endsAt']) : null,
                );
            }
            if ([] !== $sessions) {
                $byDay[$day->format('Y-m-d')] = $sessions;
            }
        }

        return $byDay;
    }

    /**
     * The next times the same class is given after one, soonest first — "the same class" being whatever
     * the caller says (same groups and subject). Same day and a later period counts. Only within that
     * course: the next one does not carry on from this one.
     *
     * @param User                        $teacher   the teacher
     * @param \DateTimeImmutable          $day       the day of the class to start after
     * @param int                         $slotIndex its period
     * @param callable(ClassSession):bool $isSame    whether a class is the same class
     * @param int                         $count     how many at most
     *
     * @return list<array{date: \DateTimeImmutable, class: ClassSession}> the classes
     */
    public function following(User $teacher, \DateTimeImmutable $day, int $slotIndex, callable $isSame, int $count): array
    {
        return $this->sameClass($teacher, $day, $slotIndex, $isSame, $count, 1);
    }

    /**
     * The last times the same class was given before one, nearest first. Mirror of {@see following()}.
     *
     * @param User                        $teacher   the teacher
     * @param \DateTimeImmutable          $day       the day of the class to start before
     * @param int                         $slotIndex its period
     * @param callable(ClassSession):bool $isSame    whether a class is the same class
     * @param int                         $count     how many at most
     *
     * @return list<array{date: \DateTimeImmutable, class: ClassSession}> the classes
     */
    public function preceding(User $teacher, \DateTimeImmutable $day, int $slotIndex, callable $isSame, int $count): array
    {
        return $this->sameClass($teacher, $day, $slotIndex, $isSame, $count, -1);
    }

    /**
     * Walks the timetable from one class, forwards or backwards, collecting the same class. Day by day the
     * timetable costs a few queries, so the look starts short and grows only when the class is further
     * away (a holiday), and it never leaves the course of the day it starts from.
     *
     * @param User                        $teacher   the teacher
     * @param \DateTimeImmutable          $day       the day of the class to start from
     * @param int                         $slotIndex its period
     * @param callable(ClassSession):bool $isSame    whether a class is the same class
     * @param int                         $count     how many at most
     * @param int                         $direction 1 forwards, -1 backwards
     *
     * @return list<array{date: \DateTimeImmutable, class: ClassSession}> the classes, nearest first
     */
    private function sameClass(User $teacher, \DateTimeImmutable $day, int $slotIndex, callable $isSame, int $count, int $direction): array
    {
        $start = $day->setTime(0, 0);
        // The course's calendar span, in the day's own zone: compared across zones, 1 September at midnight
        // in Madrid would fall before 1 September at midnight in UTC.
        [$courseStart, $courseEnd] = array_map(
            static fn (\DateTimeImmutable $d): \DateTimeImmutable => new \DateTimeImmutable($d->format('Y-m-d'), $start->getTimezone()),
            SchoolYear::bounds(SchoolYear::current($start)),
        );
        $found = [];

        for ($offset = 0, $span = self::FIRST_WINDOW_DAYS; \count($found) < $count; $offset += $span, $span = self::WINDOW_DAYS) {
            $near = $start->modify(sprintf('%+d days', $direction * $offset));
            $far = $start->modify(sprintf('%+d days', $direction * ($offset + $span - 1)));
            [$from, $to] = $direction > 0 ? [$near, min($far, $courseEnd)] : [max($far, $courseStart), $near];
            if ($from > $to) {
                break;
            }

            $byDay = $this->between($teacher, $from, $to);
            foreach ($direction > 0 ? $byDay : array_reverse($byDay, true) as $date => $sessions) {
                $date = new \DateTimeImmutable($date, $start->getTimezone());
                foreach ($direction > 0 ? $sessions : array_reverse($sessions) as $session) {
                    $beyond = $date != $start || ($direction > 0 ? $session->slotIndex > $slotIndex : $session->slotIndex < $slotIndex);
                    if ($beyond && $isSame($session)) {
                        $found[] = ['date' => $date, 'class' => $session];
                        if (\count($found) === $count) {
                            return $found;
                        }
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Whether a day is a teaching day of its course: within its first and last day, and neither a
     * weekend nor a registered non-teaching day. The calendar alone does not know when a course starts.
     *
     * @param AcademicYear       $year the course the day belongs to
     * @param \DateTimeImmutable $day  the day
     *
     * @return bool true when there are classes
     */
    private function isTeachingDay(AcademicYear $year, \DateTimeImmutable $day): bool
    {
        return $day >= $year->getYearStart()->setTime(0, 0)
            && $day <= $year->getYearEnd()->setTime(0, 0)
            && $this->calendar->isLective($day);
    }
}
