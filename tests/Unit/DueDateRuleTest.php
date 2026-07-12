<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\DueDate\DueDateRuleFactory;
use App\DueDate\FixedDate;
use App\DueDate\MonthlyOnDay;
use App\DueDate\NthWeekdayOfMonth;
use App\DueDate\PerTerm;
use App\DueDate\RelativeToAnchor;
use App\Entity\AcademicYear;
use App\Enum\CalendarAnchor;
use App\Enum\TermBoundary;
use App\Enum\Weekday;
use App\Enum\WeekOrdinal;
use PHPUnit\Framework\TestCase;

/**
 * The deadline rules resolve against a course's term structure. All cases run against a fixed
 * 2026-2027 course (terms Sep→Dec, Jan→Mar, Apr→Jun) so the expected dates are stable. Where the
 * arithmetic is fiddly (Nth weekday), the assertions check the defining properties rather than a
 * hand-computed day, so a wrong expectation cannot hide a wrong implementation.
 */
final class DueDateRuleTest extends TestCase
{
    private function year(): AcademicYear
    {
        return (new AcademicYear())
            ->setSchoolYear('2026-2027')
            ->setTerm1Start(new \DateTimeImmutable('2026-09-15'))
            ->setTerm1End(new \DateTimeImmutable('2026-12-22'))
            ->setTerm2Start(new \DateTimeImmutable('2027-01-08'))
            ->setTerm2End(new \DateTimeImmutable('2027-03-27'))
            ->setTerm3Start(new \DateTimeImmutable('2027-04-07'))
            ->setTerm3End(new \DateTimeImmutable('2027-06-22'));
    }

    /**
     * @param list<\DateTimeImmutable> $dates
     *
     * @return list<string>
     */
    private function iso(array $dates): array
    {
        return array_map(static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d'), $dates);
    }

    public function testFixedDatePicksTheYearFromTheMonth(): void
    {
        // September belongs to the first calendar year, June to the second.
        self::assertSame(['2026-09-30'], $this->iso((new FixedDate(9, 30))->resolve($this->year())));
        self::assertSame(['2027-06-01'], $this->iso((new FixedDate(6, 1))->resolve($this->year())));
    }

    public function testFixedDateClampsAnOverflowingDay(): void
    {
        // 30 February does not exist: clamp to the last day of the month (2027 is not a leap year).
        self::assertSame(['2027-02-28'], $this->iso((new FixedDate(2, 30))->resolve($this->year())));
    }

    public function testNthWeekdayFirstMonday(): void
    {
        $date = (new NthWeekdayOfMonth(WeekOrdinal::FIRST, Weekday::MONDAY, 10))->resolve($this->year())[0];

        self::assertSame('2026', $date->format('Y'));
        self::assertSame('10', $date->format('m'));
        self::assertSame(1, (int) $date->format('N'), 'is a Monday');
        self::assertLessThanOrEqual(7, (int) $date->format('j'), 'is in the first week');
    }

    public function testNthWeekdayLastFriday(): void
    {
        $date = (new NthWeekdayOfMonth(WeekOrdinal::LAST, Weekday::FRIDAY, 6))->resolve($this->year())[0];
        $daysInMonth = (int) $date->format('t');

        self::assertSame('2027-06', $date->format('Y-m'));
        self::assertSame(5, (int) $date->format('N'), 'is a Friday');
        self::assertGreaterThan($daysInMonth, (int) $date->format('j') + 7, 'no later Friday fits in the month');
    }

    public function testRelativeToAnchorShiftsByOffset(): void
    {
        self::assertSame(['2026-12-17'], $this->iso((new RelativeToAnchor(CalendarAnchor::TERM_1_END, -5))->resolve($this->year())));
        self::assertSame(['2026-09-15'], $this->iso((new RelativeToAnchor(CalendarAnchor::YEAR_START, 0))->resolve($this->year())));
    }

    public function testMonthlyStaysWithinTheCourse(): void
    {
        // The 5th of each month: September's 5th precedes the first teaching day (15 Sep) and is
        // dropped, so the run is October→June = 9 occurrences.
        $dates = $this->iso((new MonthlyOnDay(5))->resolve($this->year()));

        self::assertCount(9, $dates);
        self::assertSame('2026-10-05', $dates[0]);
        self::assertSame('2027-06-05', $dates[8]);
    }

    public function testPerTermYieldsEachTermBoundary(): void
    {
        self::assertSame(
            ['2026-12-22', '2027-03-27', '2027-06-22'],
            $this->iso((new PerTerm(TermBoundary::END))->resolve($this->year())),
        );
        self::assertSame(
            ['2026-09-15', '2027-01-08', '2027-04-07'],
            $this->iso((new PerTerm(TermBoundary::START))->resolve($this->year())),
        );
    }

    public function testFactoryRoundTripsEveryKind(): void
    {
        $rules = [
            new FixedDate(9, 30),
            new NthWeekdayOfMonth(WeekOrdinal::LAST, Weekday::FRIDAY, 6),
            new RelativeToAnchor(CalendarAnchor::TERM_2_START, 3),
            new MonthlyOnDay(5),
            new PerTerm(TermBoundary::END),
        ];

        foreach ($rules as $rule) {
            $rebuilt = DueDateRuleFactory::fromArray($rule->toArray());
            self::assertSame($rule->toArray(), $rebuilt->toArray(), $rule::class.' survives a round-trip');
            self::assertEquals($rule->resolve($this->year()), $rebuilt->resolve($this->year()));
        }
    }

    public function testFactoryRejectsUnknownKind(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DueDateRuleFactory::fromArray(['kind' => 'nope']);
    }
}
