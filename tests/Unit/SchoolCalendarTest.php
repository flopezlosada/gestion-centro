<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repository\NonLectiveDayRepository;
use App\Service\SchoolCalendar;
use PHPUnit\Framework\TestCase;

/**
 * A day is teaching (lective) when it is neither a weekend nor a registered non-teaching day. The
 * repository lookup is stubbed so these stay pure (no database).
 */
final class SchoolCalendarTest extends TestCase
{
    public function testWeekendIsNotLective(): void
    {
        $repo = $this->createMock(NonLectiveDayRepository::class);
        // Weekends short-circuit before the repository is even consulted.
        $repo->expects(self::never())->method('existsOn');
        $calendar = new SchoolCalendar($repo);

        self::assertFalse($calendar->isLective(new \DateTimeImmutable('2026-07-11')), 'Saturday is not lective');
        self::assertFalse($calendar->isLective(new \DateTimeImmutable('2026-07-12')), 'Sunday is not lective');
    }

    public function testRegisteredHolidayIsNotLective(): void
    {
        $repo = $this->createMock(NonLectiveDayRepository::class);
        $repo->method('existsOn')->willReturn(true);
        $calendar = new SchoolCalendar($repo);

        // A Monday that is registered as a non-teaching day.
        self::assertFalse($calendar->isLective(new \DateTimeImmutable('2026-07-13')));
    }

    public function testPlainWeekdayIsLective(): void
    {
        $repo = $this->createMock(NonLectiveDayRepository::class);
        $repo->method('existsOn')->willReturn(false);
        $calendar = new SchoolCalendar($repo);

        // A Monday that is not registered anywhere.
        self::assertTrue($calendar->isLective(new \DateTimeImmutable('2026-07-13')));
    }

    public function testIsWeekendRecognisesSaturdayAndSunday(): void
    {
        $calendar = new SchoolCalendar($this->createMock(NonLectiveDayRepository::class));

        self::assertTrue($calendar->isWeekend(new \DateTimeImmutable('2026-07-11')), 'Saturday');
        self::assertTrue($calendar->isWeekend(new \DateTimeImmutable('2026-07-12')), 'Sunday');
        self::assertFalse($calendar->isWeekend(new \DateTimeImmutable('2026-07-13')), 'Monday');
    }
}
