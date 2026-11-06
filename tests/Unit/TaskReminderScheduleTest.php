<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Task;
use App\Entity\TaskReminderSchedule;
use App\Entity\User;
use App\Enum\TaskReminderRepeat;
use App\Enum\TaskReminderStart;
use App\Enum\TaskType;
use PHPUnit\Framework\TestCase;

/**
 * Which days before the deadline a person's own task reminders fall on: the start, then every repeat
 * gap down to the day before the deadline, never on it; and the old 15 + 7 when they chose nothing.
 */
final class TaskReminderScheduleTest extends TestCase
{
    private function schedule(TaskReminderStart $start, TaskReminderRepeat $repeat): TaskReminderSchedule
    {
        return new TaskReminderSchedule(new Task('Memoria', '2025-2026', new \DateTimeImmutable('2026-06-30'), TaskType::SIMPLE), new User(), $start, $repeat);
    }

    public function testNoScheduleKeepsTheRemindersEverybodyHadBefore(): void
    {
        self::assertSame([15, 7], TaskReminderSchedule::daysBeforeFor(null));
    }

    public function testOnceIsJustTheStart(): void
    {
        self::assertSame([3], $this->schedule(TaskReminderStart::THREE_DAYS, TaskReminderRepeat::ONCE)->daysBefore());
    }

    public function testRepeatingCountsDownToTheDayBeforeTheDeadline(): void
    {
        self::assertSame([5, 3, 1], $this->schedule(TaskReminderStart::FIVE_DAYS, TaskReminderRepeat::EVERY_TWO_DAYS)->daysBefore());
        self::assertSame([7, 6, 5, 4, 3, 2, 1], $this->schedule(TaskReminderStart::ONE_WEEK, TaskReminderRepeat::DAILY)->daysBefore());
    }

    public function testAGapThatDoesNotDivideTheStartStopsBeforeTheDeadline(): void
    {
        // 30, 23, 16, 9, 2: the next one would be past the deadline, so it is not there.
        self::assertSame([30, 23, 16, 9, 2], $this->schedule(TaskReminderStart::ONE_MONTH, TaskReminderRepeat::WEEKLY)->daysBefore());
    }

    public function testRepeatingFromTheLastDayIsJustThatDay(): void
    {
        self::assertSame([1], $this->schedule(TaskReminderStart::ONE_DAY, TaskReminderRepeat::WEEKLY)->daysBefore());
    }

    public function testEveryChoiceOnTheMenuGivesDaysStartingOnItsAntelacion(): void
    {
        // All of them, not a sample: range() used to throw on "2 días antes, cada 2 días" and six other
        // pairs, which would have stopped the nightly sweep for everybody.
        foreach (TaskReminderStart::cases() as $start) {
            foreach (TaskReminderRepeat::cases() as $repeat) {
                $days = $this->schedule($start, $repeat)->daysBefore();

                self::assertSame($start->value, $days[0] ?? null, \sprintf('%s, %s', $start->label(), $repeat->label()));
                self::assertGreaterThanOrEqual(1, min($days));
            }
        }
    }

    public function testAGapAsLongAsTheAntelacionIsJustTheFirstDay(): void
    {
        self::assertSame([2], $this->schedule(TaskReminderStart::TWO_DAYS, TaskReminderRepeat::EVERY_TWO_DAYS)->daysBefore());
        self::assertSame([7], $this->schedule(TaskReminderStart::ONE_WEEK, TaskReminderRepeat::WEEKLY)->daysBefore());
    }

    public function testTheDefaultInWordsMatchesWhatTheSweepDoes(): void
    {
        self::assertSame('15 y 7 días antes', TaskReminderSchedule::defaultLabel());
    }

    public function testChangingTheScheduleChangesTheDays(): void
    {
        $schedule = $this->schedule(TaskReminderStart::ONE_DAY, TaskReminderRepeat::ONCE)
            ->change(TaskReminderStart::TEN_DAYS, TaskReminderRepeat::EVERY_THREE_DAYS);

        self::assertSame([10, 7, 4, 1], $schedule->daysBefore());
    }
}
