<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Agenda\RecurrenceExpander;
use PHPUnit\Framework\TestCase;

/**
 * The recurrence expander turns a start day, a frequency and an end day into the concrete occurrence
 * instants, keeping the time-of-day, clamping the monthly day to each month's length, and never
 * exceeding its hard cap.
 */
final class RecurrenceExpanderTest extends TestCase
{
    private RecurrenceExpander $expander;

    protected function setUp(): void
    {
        $this->expander = new RecurrenceExpander();
    }

    /**
     * @return list<string>
     */
    private function days(\DateTimeImmutable $start, string $freq, \DateTimeImmutable $until): array
    {
        return array_map(
            static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d'),
            $this->expander->expand($start, $freq, $until),
        );
    }

    public function testNonRecurringYieldsTheSingleStart(): void
    {
        $start = new \DateTimeImmutable('2026-03-02 10:00');
        $occurrences = $this->expander->expand($start, RecurrenceExpander::NONE, $start);

        self::assertSame(
            ['2026-03-02 10:00'],
            array_map(static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d H:i'), $occurrences),
        );
    }

    public function testWeeklyStepsBySevenDaysInclusiveOfTheEnd(): void
    {
        $days = $this->days(
            new \DateTimeImmutable('2026-03-02'),
            RecurrenceExpander::WEEKLY,
            new \DateTimeImmutable('2026-03-30'),
        );

        self::assertSame(['2026-03-02', '2026-03-09', '2026-03-16', '2026-03-23', '2026-03-30'], $days);
    }

    public function testWeeklyKeepsTheTimeOfDay(): void
    {
        $occurrences = $this->expander->expand(
            new \DateTimeImmutable('2026-03-02 09:30'),
            RecurrenceExpander::WEEKLY,
            new \DateTimeImmutable('2026-03-16'),
        );

        foreach ($occurrences as $occurrence) {
            self::assertSame('09:30', $occurrence->format('H:i'));
        }
    }

    public function testMonthlyClampsTheDayToShortMonths(): void
    {
        // The 31st, monthly, must land on the last day of shorter months, never overflow into the next.
        $days = $this->days(
            new \DateTimeImmutable('2026-01-31'),
            RecurrenceExpander::MONTHLY,
            new \DateTimeImmutable('2026-04-30'),
        );

        self::assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'], $days);
    }

    public function testMonthlyOnAStableDay(): void
    {
        $days = $this->days(
            new \DateTimeImmutable('2026-01-15'),
            RecurrenceExpander::MONTHLY,
            new \DateTimeImmutable('2026-04-15'),
        );

        self::assertSame(['2026-01-15', '2026-02-15', '2026-03-15', '2026-04-15'], $days);
    }

    public function testNeverExceedsTheHardCap(): void
    {
        // A ten-year weekly range would be ~520 occurrences; the cap must bound it.
        $occurrences = $this->expander->expand(
            new \DateTimeImmutable('2026-01-01'),
            RecurrenceExpander::WEEKLY,
            new \DateTimeImmutable('2036-01-01'),
        );

        self::assertCount(RecurrenceExpander::MAX_OCCURRENCES, $occurrences);
    }
}
