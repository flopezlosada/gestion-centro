<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\NonLectiveDay;
use App\Repository\AcademicYearRepository;
use App\Repository\NonLectiveDayRepository;
use App\Util\SchoolYear;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Answers whether a given day is a teaching day of the school calendar. A day is teaching (lective)
 * when it is not a weekend, not in a break between two terms of its course ({@see self::breakOn()}) and
 * not a registered non-teaching day ({@see \App\Entity\NonLectiveDay}).
 *
 * The breaks come from the course's own term dates, so Christmas and Easter need not be typed in one day
 * at a time every year: forgetting them used to put guardias and deadlines on 28 December, silently.
 * The edges of the course are NOT breaks: from 1 September to the first term and from the last term to
 * the end of June there are no classes, but staff work and the centre's own deadlines fall there
 * («Antes del 23 de junio»). Whoever needs actual classes bounds the day by the course as well
 * ({@see \App\Agenda\MyClasses}, {@see RecurringMeetingGenerator}).
 *
 * This is the single source of truth for "may a task deadline fall here?" and for marking the
 * calendar grid.
 */
final class SchoolCalendar implements ResetInterface
{
    /** The break after each term but the last, by the number of the term it follows. */
    private const array BREAKS = [1 => 'Vacaciones de Navidad', 2 => 'Vacaciones de Semana Santa'];

    /**
     * The course of each school year, read once per request: callers walk whole weeks and months day by
     * day. A missing course is kept as null so it is not looked up again.
     *
     * @var array<string, ?AcademicYear>
     */
    private array $years = [];

    public function __construct(
        private readonly NonLectiveDayRepository $nonLectiveDays,
        private readonly AcademicYearRepository $academicYears,
    ) {
    }

    /**
     * Forgets the courses between requests, so a long-running process does not keep term dates that
     * have since been edited.
     */
    public function reset(): void
    {
        $this->years = [];
    }

    /**
     * Whether the given day is a teaching day: not a weekend, not in a break between terms and not a
     * registered non-teaching day.
     *
     * @param \DateTimeImmutable $date the day to check (time part is ignored)
     *
     * @return bool true if tasks may be due on that day
     */
    public function isLective(\DateTimeImmutable $date): bool
    {
        return $this->isOpen($date, fn (): bool => $this->nonLectiveDays->existsOn($date));
    }

    /**
     * Every teaching day between two dates, inclusive — the same answer as {@see isLective()} day by day,
     * with the registered non-teaching days read in ONE query: a fixed booking walks a whole course, and
     * asking day by day would be one query per day.
     *
     * @param \DateTimeImmutable $from the first day (time part is ignored)
     * @param \DateTimeImmutable $to   the last day (time part is ignored)
     *
     * @return list<\DateTimeImmutable> the teaching days, at midnight, in order
     */
    public function lectiveDaysBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $from = $from->setTime(0, 0);
        $to = $to->setTime(0, 0);
        $closed = array_flip(array_map(
            static fn (NonLectiveDay $day): string => $day->getDate()->format('Y-m-d'),
            $this->nonLectiveDays->findBetween($from, $to),
        ));

        $days = [];
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            if ($this->isOpen($day, static fn (): bool => isset($closed[$day->format('Y-m-d')]))) {
                $days[] = $day;
            }
        }

        return $days;
    }

    /**
     * The break between two terms the given day falls in, by name, or null if it falls in none. A day
     * whose course does not exist yet is in no break: without term dates nothing can be said, and the
     * registered non-teaching days still apply.
     *
     * @param \DateTimeImmutable $date the day to check (time part is ignored)
     *
     * @return string|null the break's name («Vacaciones de Navidad»), or null
     */
    public function breakOn(\DateTimeImmutable $date): ?string
    {
        $year = $this->courseOf($date);
        if (null === $year) {
            return null;
        }

        $day = $date->format('Y-m-d');
        $found = array_filter(
            self::BREAKS,
            static fn (int $term): bool => $day > $year->getTermEnd($term)->format('Y-m-d') && $day < $year->getTermStart($term + 1)->format('Y-m-d'),
            \ARRAY_FILTER_USE_KEY,
        );

        return [] === $found ? null : reset($found);
    }

    /**
     * Whether the given day falls on a weekend (Saturday or Sunday), which is non-teaching by
     * definition and not stored as a {@see \App\Entity\NonLectiveDay}.
     *
     * @param \DateTimeImmutable $date the day to check
     *
     * @return bool true for Saturday or Sunday
     */
    public function isWeekend(\DateTimeImmutable $date): bool
    {
        // ISO-8601 day of week: 6 = Saturday, 7 = Sunday.
        return (int) $date->format('N') >= 6;
    }

    /**
     * The given day if it is a teaching day, otherwise the nearest earlier teaching day. Used when a
     * computed deadline lands on a weekend or holiday: a deadline is moved earlier, never later, so it
     * still falls within its intended period. Bounded so a long closure can never loop forever — if no
     * teaching day is found within a month back, the original date is returned unchanged.
     *
     * @param \DateTimeImmutable $date the candidate deadline
     *
     * @return \DateTimeImmutable a teaching day on or before it (or the input if none is found)
     */
    public function onOrBeforeLectiveDay(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $day = $date;
        for ($i = 0; $i < 31; ++$i) {
            if ($this->isLective($day)) {
                return $day;
            }
            $day = $day->modify('-1 day');
        }

        return $date;
    }

    /**
     * The rule of a teaching day, in one place for {@see isLective()} and {@see lectiveDaysBetween()}: not a
     * weekend, not in a break, and not registered as closed. The last check is a closure because the two
     * callers answer it differently (one query, or a set read beforehand) and it is the only one that costs.
     *
     * @param \DateTimeImmutable $date       the day
     * @param \Closure(): bool   $registered whether the day is a registered non-teaching day
     *
     * @return bool true for a teaching day
     */
    private function isOpen(\DateTimeImmutable $date, \Closure $registered): bool
    {
        return !$this->isWeekend($date) && null === $this->breakOn($date) && !$registered();
    }

    /**
     * The course the given day belongs to, looked up once per school year.
     *
     * @param \DateTimeImmutable $date the day
     *
     * @return AcademicYear|null the course, or null if it has not been created
     */
    private function courseOf(\DateTimeImmutable $date): ?AcademicYear
    {
        $schoolYear = SchoolYear::current($date);
        if (!\array_key_exists($schoolYear, $this->years)) {
            $this->years[$schoolYear] = $this->academicYears->findBySchoolYear($schoolYear);
        }

        return $this->years[$schoolYear];
    }
}
