<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Enum\EventReminderOffset;
use PHPUnit\Framework\TestCase;

/**
 * The reminder offsets an agenda event or a meeting can pick from.
 */
final class EventReminderOffsetTest extends TestCase
{
    /**
     * A reminder opens Inicio, which only lists the next seven days: an option further ahead would
     * open a screen where the event is not.
     */
    public function testNoOffsetGoesFurtherAheadThanInicioShows(): void
    {
        foreach (EventReminderOffset::cases() as $offset) {
            self::assertLessThanOrEqual(EventReminderOffset::MAX_MINUTES_AHEAD, $offset->value, $offset->name);
        }
    }

    /**
     * The options are shown as chips in this order, so it must read from nearest to furthest.
     */
    public function testOffsetsAreListedFromNearestToFurthest(): void
    {
        $values = array_map(static fn (EventReminderOffset $offset): int => $offset->value, EventReminderOffset::cases());
        $sorted = $values;
        sort($sorted);

        self::assertSame($sorted, $values);
    }

    public function testEveryOffsetHasItsOwnLabel(): void
    {
        $labels = array_map(static fn (EventReminderOffset $offset): string => $offset->label(), EventReminderOffset::cases());

        self::assertSame($labels, array_values(array_unique($labels)));
        self::assertSame('2 días antes', EventReminderOffset::TWO_DAYS->label());
        self::assertSame('1 semana antes', EventReminderOffset::ONE_WEEK->label());
    }
}
