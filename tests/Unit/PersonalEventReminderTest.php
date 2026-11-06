<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\PersonalEvent;
use App\Entity\User;
use App\Enum\EventReminderOffset;
use PHPUnit\Framework\TestCase;

/**
 * The reminder side of {@see PersonalEvent}: the instant it fires is DERIVED (never set from outside),
 * an entry with no time can never carry one, and re-arming only happens when the schedule really moved
 * — otherwise saving an event twice would push the same reminder twice.
 */
final class PersonalEventReminderTest extends TestCase
{
    private function event(string $start = '2026-09-15 10:00'): PersonalEvent
    {
        return new PersonalEvent(new User(), 'Tutoría', new \DateTimeImmutable($start));
    }

    public function testAnEventWithoutAReminderHasNoRemindAt(): void
    {
        self::assertNull($this->event()->getRemindAt());
    }

    public function testRemindAtIsTheStartMinusTheOffset(): void
    {
        $event = $this->event()->setReminders([EventReminderOffset::TEN_MINUTES]);

        self::assertSame('2026-09-15 09:50', $event->getRemindAt()?->format('Y-m-d H:i'));
    }

    public function testADayAheadReminderCrossesIntoThePreviousDay(): void
    {
        $event = $this->event()->setReminders([EventReminderOffset::ONE_DAY]);

        self::assertSame('2026-09-14 10:00', $event->getRemindAt()?->format('Y-m-d H:i'));
    }

    public function testClearingTheRemindersClearsTheInstant(): void
    {
        $event = $this->event()->setReminders([EventReminderOffset::ONE_HOUR])->setReminders([]);

        self::assertNull($event->getRemindAt());
    }

    public function testMovingTheStartMovesTheReminder(): void
    {
        $event = $this->event()->setReminders([EventReminderOffset::FIFTEEN_MINUTES]);

        $event->setStartAt(new \DateTimeImmutable('2026-09-16 08:30'));

        self::assertSame('2026-09-16 08:15', $event->getRemindAt()?->format('Y-m-d H:i'));
    }

    public function testAnAllDayEntryCannotCarryAReminderWhicheverOrderItIsSet(): void
    {
        // Both orders must land on the same place: no reminder. Otherwise the create/edit flow could
        // store a reminder that would fire at midnight-minus-ten, which is not what anybody asked for.
        $first = $this->event()->setAllDay(true)->setReminders([EventReminderOffset::TEN_MINUTES]);
        $second = $this->event()->setReminders([EventReminderOffset::TEN_MINUTES])->setAllDay(true);

        self::assertSame([], $first->getReminders());
        self::assertNull($first->getRemindAt());
        self::assertSame([], $second->getReminders());
        self::assertNull($second->getRemindAt());
    }

    public function testRewritingTheSameScheduleDoesNotReArmAnAlreadySentReminder(): void
    {
        // The edit form always rewrites start/end/reminder, even when only the title changed. If that
        // cleared the "sent" mark, editing the title would push the reminder a second time.
        $event = $this->event()->setReminders([EventReminderOffset::TEN_MINUTES]);
        $event->markReminderSent(new \DateTimeImmutable('2026-09-15 09:50'));

        $event->setStartAt(new \DateTimeImmutable('2026-09-15 10:00'))
            ->setReminders([EventReminderOffset::TEN_MINUTES]);

        self::assertNotNull($event->getReminderSentAt());
    }

    public function testMovingTheEventLaterReArmsAnAlreadySentReminder(): void
    {
        $event = $this->event()->setReminders([EventReminderOffset::TEN_MINUTES]);
        $event->markReminderSent(new \DateTimeImmutable('2026-09-15 09:50'));

        $event->setStartAt(new \DateTimeImmutable('2026-09-15 17:00'));

        self::assertSame('2026-09-15 16:50', $event->getRemindAt()?->format('Y-m-d H:i'));
    }

    public function testSeveralRemindersAreKeptEarliestFirstAndWithoutRepeats(): void
    {
        $event = $this->event()->setReminders([
            EventReminderOffset::TEN_MINUTES,
            EventReminderOffset::ONE_DAY,
            EventReminderOffset::TEN_MINUTES,
        ]);

        self::assertSame([EventReminderOffset::ONE_DAY, EventReminderOffset::TEN_MINUTES], $event->getReminders());
        self::assertSame('2026-09-14 10:00', $event->getRemindAt()?->format('Y-m-d H:i'));
    }

    public function testSendingOneReminderMovesOnToTheNextAndTheLastLeavesNone(): void
    {
        $event = $this->event()->setReminders([EventReminderOffset::ONE_DAY, EventReminderOffset::TEN_MINUTES]);

        $event->markReminderSent(new \DateTimeImmutable('2026-09-14 10:00'));
        self::assertSame('2026-09-15 09:50', $event->getRemindAt()?->format('Y-m-d H:i'));

        $event->markReminderSent(new \DateTimeImmutable('2026-09-15 09:50'));
        self::assertNull($event->getRemindAt());
    }

    public function testAReminderThatCameDueTogetherWithAnotherGoesOutInTheSamePush(): void
    {
        // A late run (the cron was down) finds both the hour-ahead and the ten-minute reminder due:
        // one push covers them, instead of two in a row saying the same thing.
        $event = $this->event()->setReminders([EventReminderOffset::ONE_HOUR, EventReminderOffset::TEN_MINUTES]);

        $event->markReminderSent(new \DateTimeImmutable('2026-09-15 09:55'));

        self::assertNull($event->getRemindAt());
    }

    public function testAReminderAddedBehindTheLastPushDoesNotRepeatIt(): void
    {
        // The ten-minute reminder already went out; adding "30 minutos antes" now (its moment was
        // 09:30) would only push the same thing again.
        $event = $this->event()->setReminders([EventReminderOffset::TEN_MINUTES]);
        $event->markReminderSent(new \DateTimeImmutable('2026-09-15 09:50'));

        $event->setReminders([EventReminderOffset::THIRTY_MINUTES, EventReminderOffset::TEN_MINUTES]);

        self::assertNull($event->getRemindAt());
    }

    public function testAReminderAddedAfterTheLastPushStillFiresIfItIsAhead(): void
    {
        $event = $this->event()->setReminders([EventReminderOffset::TEN_MINUTES]);
        $event->markReminderSent(new \DateTimeImmutable('2026-09-15 09:50'));

        $event->setReminders([EventReminderOffset::TEN_MINUTES, EventReminderOffset::FIVE_MINUTES]);

        self::assertSame('2026-09-15 09:55', $event->getRemindAt()?->format('Y-m-d H:i'));
    }
}
