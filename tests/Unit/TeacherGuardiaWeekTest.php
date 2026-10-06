<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Agenda\DutySlot;
use App\Entity\GuardiaCover;
use App\Enum\ScheduleActivityKind;
use App\Guardia\GuardiaWeekSlot;
use App\Guardia\TeacherGuardiaWeek;
use PHPUnit\Framework\TestCase;

/**
 * The guardia week of «mis guardias»: which week it shows, how covers land on the duty periods, and what
 * becomes of a cover that falls outside them.
 *
 * Tested here and not through the screen because the screen reads "today" from the real clock: a fixed week
 * is the only way to reach the weekend and a past period on demand.
 */
final class TeacherGuardiaWeekTest extends TestCase
{
    public function testShowsTheCurrentWeekFromMondayToFriday(): void
    {
        $days = TeacherGuardiaWeek::daysAround(new \DateTimeImmutable('2026-10-07')); // miércoles

        self::assertSame(['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09'], self::keys($days));
    }

    /** On a weekend the week just ended is nothing to plan for: the next one is shown. */
    public function testOnAWeekendShowsTheNextWeek(): void
    {
        self::assertSame('2026-10-12', self::keys(TeacherGuardiaWeek::daysAround(new \DateTimeImmutable('2026-10-10')))[0], 'sábado');
        self::assertSame('2026-10-12', self::keys(TeacherGuardiaWeek::daysAround(new \DateTimeImmutable('2026-10-11')))[0], 'domingo');
    }

    /** A cover in one of the teacher's duty periods fills that box; the other duty periods stay empty. */
    public function testACoverFillsTheDutyPeriodItFallsIn(): void
    {
        $tuesday = new \DateTimeImmutable('2026-10-06');
        $cover = self::cover($tuesday, 2);

        $week = $this->week([$tuesday->format('Y-m-d') => [self::duty($tuesday, 2, '10:15', '11:10'), self::duty($tuesday, 4, '11:35', '12:30')]], [$cover]);

        $slots = $week[1]['slots'];
        self::assertCount(2, $slots);
        self::assertSame([$cover], $slots[0]->covers);
        self::assertFalse($slots[0]->isOffDuty());
        self::assertFalse($slots[1]->isCovering());
        self::assertSame('Guardia', $slots[1]->label);
        self::assertSame([], $week[0]['slots'], 'lunes sin guardias');
    }

    /**
     * A cover outside every duty period (a doblete, a one-off support) still gets a box of its own, in period
     * order and timed from the course's periods — otherwise it would vanish from the week.
     */
    public function testACoverOutsideTheDutyPeriodsGetsItsOwnBox(): void
    {
        $friday = new \DateTimeImmutable('2026-10-09');
        $cover = self::cover($friday, 0);

        $week = $this->week([$friday->format('Y-m-d') => [self::duty($friday, 3, '11:10', '11:35', true)]], [$cover]);

        $slots = $week[4]['slots'];
        self::assertSame([0, 3], array_map(static fn (GuardiaWeekSlot $s): int => $s->slotIndex, $slots));
        self::assertTrue($slots[0]->isOffDuty());
        self::assertSame('2026-10-09 08:25', $slots[0]->startsAt?->format('Y-m-d H:i'));
        self::assertSame('Guardia de recreo', $slots[1]->label);
        self::assertTrue($slots[1]->duringBreak, 'en el recreo se vigila, no se cubre a nadie');
        self::assertFalse($slots[0]->duringBreak);
    }

    /** Two covers in the same period (an agrupación) share one box. */
    public function testTwoCoversInOnePeriodShareTheBox(): void
    {
        $monday = new \DateTimeImmutable('2026-10-05');
        $covers = [self::cover($monday, 1), self::cover($monday, 1)];

        $week = $this->week([$monday->format('Y-m-d') => [self::duty($monday, 1, '09:20', '10:15')]], $covers);

        self::assertCount(1, $week[0]['slots']);
        self::assertSame($covers, $week[0]['slots'][0]->covers);
    }

    /** A period counts as past once it has ENDED, not once it has started. */
    public function testAPeriodIsPastOnlyOnceItHasEnded(): void
    {
        $tuesday = new \DateTimeImmutable('2026-10-06');
        $duties = [$tuesday->format('Y-m-d') => [self::duty($tuesday, 1, '09:20', '10:15'), self::duty($tuesday, 2, '10:15', '11:10')]];

        $week = $this->week($duties, [], now: '2026-10-06 10:30');

        self::assertTrue($week[1]['slots'][0]->past);
        self::assertFalse($week[1]['slots'][1]->past, 'en curso');
        self::assertTrue($week[1]['isToday']);
    }

    /** An off-duty cover whose period has no known times is only past once its whole day is. */
    public function testAnUntimedCoverIsPastOnlyOnceItsDayIsOver(): void
    {
        $tuesday = new \DateTimeImmutable('2026-10-06');

        $sameDay = $this->week([], [self::cover($tuesday, 9)], now: '2026-10-06 23:00');
        $nextDay = $this->week([], [self::cover($tuesday, 9)], now: '2026-10-07 00:00');

        self::assertFalse($sameDay[1]['slots'][0]->past);
        self::assertTrue($nextDay[1]['slots'][0]->past);
    }

    /**
     * Builds the week around $now, with period 0 (08:25–09:20) as the only timetable period.
     *
     * @param array<string, list<DutySlot>> $dutiesByDay the duty periods by "Y-m-d"
     * @param list<GuardiaCover>            $covers      the covers
     * @param string                        $now         the current instant
     *
     * @return list<array{date: \DateTimeImmutable, isToday: bool, slots: list<GuardiaWeekSlot>}> the week
     */
    private function week(array $dutiesByDay, array $covers, string $now = '2026-10-06 08:00'): array
    {
        $now = new \DateTimeImmutable($now);
        $today = $now->setTime(0, 0);
        $slotTimes = [0 => ['startsAt' => new \DateTimeImmutable('08:25'), 'endsAt' => new \DateTimeImmutable('09:20')]];

        return (new TeacherGuardiaWeek())->forDays(TeacherGuardiaWeek::daysAround($today), $dutiesByDay, $covers, $slotTimes, $today, $now);
    }

    /**
     * A duty period of a day.
     *
     * @param \DateTimeImmutable $day       the day
     * @param int                $slotIndex the period
     * @param string             $from      start time, "H:i"
     * @param string             $to        end time, "H:i"
     * @param bool               $break     whether it falls in a recreo
     *
     * @return DutySlot the period
     */
    private static function duty(\DateTimeImmutable $day, int $slotIndex, string $from, string $to, bool $break = false): DutySlot
    {
        return new DutySlot(ScheduleActivityKind::GUARDIA, $slotIndex, $break, $day->modify($from), $day->modify($to));
    }

    /**
     * A cover of a day and period; nothing else is read by the week.
     *
     * @param \DateTimeImmutable $day       the day
     * @param int                $slotIndex the period
     *
     * @return GuardiaCover the cover
     */
    private static function cover(\DateTimeImmutable $day, int $slotIndex): GuardiaCover
    {
        return (new GuardiaCover())->setDate($day)->setSlotIndex($slotIndex);
    }

    /**
     * The days as "Y-m-d", to compare them at a glance.
     *
     * @param list<\DateTimeImmutable> $days the days
     *
     * @return list<string> the keys
     */
    private static function keys(array $days): array
    {
        return array_map(static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d'), $days);
    }
}
