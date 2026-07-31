<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Room;
use App\Enum\RoomSize;
use App\Space\FreeRoomsDay;
use App\Space\FreeRoomsSlot;
use App\Space\RoomAvailability;
use PHPUnit\Framework\TestCase;

/**
 * The reading of the "aulas libres" sheet: which hour it opens on, how the free rooms are tiered, and what
 * it offers when the hour asked for has nothing.
 *
 * Tested here and not through the screen because these are the three decisions the redesign rests on, and
 * each of them has a case real data does not reach on any given day: a room nobody has measured, a period
 * that is a recreo, the clock landing between two lessons.
 */
final class FreeRoomsDayTest extends TestCase
{
    public function testOpensOnThePeriodThatHasNotFinishedYet(): void
    {
        $day = self::day(now: '10:30');

        self::assertSame(2, $day->current?->index, 'the lesson in progress is the one being lived');
    }

    public function testOpensOnTheNextLessonWhenTheClockIsInARecreo(): void
    {
        // 11:20 falls inside the recreo. Landing on it would answer with every room in the building, which
        // is no answer to "where do I put this class at fourth period".
        $day = self::day(now: '11:20');

        self::assertSame(4, $day->current?->index, 'the recreo is skipped for the lesson about to start');
    }

    public function testOpensOnFirstPeriodBeforeSchoolAndAfterIt(): void
    {
        self::assertSame(0, self::day(now: '07:10')->current?->index, 'before class, first period');
        self::assertSame(0, self::day(now: '20:00')->current?->index, 'after class it starts again at the top');
    }

    public function testOpensOnFirstPeriodOnADayThatIsNotToday(): void
    {
        // No clock: cuadrando el parte de marzo, "la hora actual" no quiere decir nada.
        self::assertSame(0, self::day(now: null)->current?->index);
    }

    public function testThePeriodAskedForWinsOverTheClock(): void
    {
        self::assertSame(5, self::day(now: '10:30', wanted: 5)->current?->index);
    }

    public function testAPeriodAskedForThatTheDayDoesNotHaveFallsBackToTheDefault(): void
    {
        // A hand-typed or stale ?slot= must not leave the screen with nothing to show.
        self::assertSame(0, self::day(now: null, wanted: 99)->current?->index);
    }

    public function testHasNoCurrentPeriodWithoutATimetable(): void
    {
        $day = FreeRoomsDay::of([], [], null, null);

        self::assertNull($day->current);
        self::assertNull($day->nextWithRooms(), 'and nothing to suggest either');
    }

    public function testTiersPutTheRoomsThatTakeTwoGroupsFirstAndTheRestAtTheEnd(): void
    {
        $slot = new FreeRoomsSlot(0, new \DateTimeImmutable('08:25'), new \DateTimeImmutable('09:20'), false, [
            self::room('S ACTOS', RoomSize::MANY_GROUPS),
            self::room('BIBL', RoomSize::TWO_GROUPS),
            self::room('A10', RoomSize::ONE_GROUP),
            self::room('DESD1', RoomSize::SMALL),
        ]);

        $tiers = $slot->tiers();

        self::assertSame(
            ['Caben 3 grupos', 'Caben 2 grupos', 'Cabe 1 grupo', 'No cabe un grupo entero'],
            array_column($tiers, 'label'),
            'biggest first, and what does not take two groups at the end',
        );
        self::assertSame([false, false, true, true], array_column($tiers, 'dimmed'), 'only the useless ones are dimmed');
        self::assertSame('no sirve para juntar', $tiers[2]['note'], 'and they say why');
    }

    public function testARoomNobodyHasMeasuredGoesAfterTheCandidatesAndIsNotDimmed(): void
    {
        // The point: "nobody said how big it is" is a missing datum, not a verdict against the room — it may
        // well be the biggest thing free. Filing it under "no sirve para juntar" would hide it.
        $slot = new FreeRoomsSlot(0, new \DateTimeImmutable('08:25'), new \DateTimeImmutable('09:20'), false, [
            self::room('BIBL', RoomSize::TWO_GROUPS),
            self::room('A10', RoomSize::ONE_GROUP),
            self::room('TALL JAR', null),
        ]);

        $tiers = $slot->tiers();

        self::assertSame(['Caben 2 grupos', 'Tamaño sin indicar', 'Cabe 1 grupo'], array_column($tiers, 'label'));
        self::assertFalse($tiers[1]['dimmed'], 'a room of unknown size is still a candidate');
        self::assertSame([self::codesOf($tiers[1]['rooms'])], [['TALL JAR']]);
    }

    public function testTheSuggestionForAnEmptyPeriodIsTheNextLessonWithRooms(): void
    {
        $day = self::day(now: null, free: [0 => [], 1 => [], 2 => ['BIBL'], 3 => ['GIM'], 4 => ['A10']]);

        self::assertSame(2, $day->nextWithRooms()?->index, 'the next teaching period that has something');
    }

    public function testTheSuggestionSkipsRecreosEvenThoughEverythingIsFreeThen(): void
    {
        // Period 3 is the recreo and holds every room; suggesting it instead of 4 would be a non-answer.
        $day = self::day(now: null, wanted: 2, free: [0 => [], 1 => [], 2 => [], 3 => ['GIM', 'BIBL'], 4 => ['A10']]);

        self::assertSame(4, $day->nextWithRooms()?->index);
    }

    public function testTheSuggestionLooksBackWhenNothingIsLeftInTheDay(): void
    {
        // At last period there is no "next", and a dead end is worse than "prueba a las 08:25".
        $day = self::day(now: null, wanted: 5, free: [0 => ['BIBL'], 1 => [], 2 => [], 3 => [], 4 => [], 5 => []]);

        self::assertSame(0, $day->nextWithRooms()?->index);
    }

    public function testThereIsNothingToSuggestWhenTheWholeDayIsFull(): void
    {
        $day = self::day(now: null, free: [0 => [], 1 => [], 2 => [], 3 => [], 4 => [], 5 => []]);

        self::assertNull($day->nextWithRooms());
    }

    /**
     * A day shaped like the centre's: six lessons with a recreo at index 3, and by default one free room in
     * each period so the counts are not the thing under test.
     *
     * @param string|null                 $now    the time of day on today's sheet, or null for another day
     * @param int|null                    $wanted the period asked for in the URL
     * @param array<int, list<string>>|null $free the codes free at each period, or null for one everywhere
     *
     * @return FreeRoomsDay the sheet
     */
    private static function day(?string $now, ?int $wanted = null, ?array $free = null): FreeRoomsDay
    {
        $times = [
            0 => ['08:25', '09:20', false],
            1 => ['09:20', '10:15', false],
            2 => ['10:15', '11:10', false],
            3 => ['11:10', '11:35', true],
            4 => ['11:35', '12:30', false],
            5 => ['12:30', '13:25', false],
        ];

        $frame = [];
        $availability = [];
        foreach ($times as $index => [$from, $to, $isBreak]) {
            $frame[] = [
                'index' => $index,
                'startsAt' => new \DateTimeImmutable($from),
                'endsAt' => new \DateTimeImmutable($to),
                'isBreak' => $isBreak,
            ];
            $codes = $free[$index] ?? ['BIBL'];
            $availability[$index] = new RoomAvailability(array_map(static fn (string $c): Room => self::room($c, RoomSize::TWO_GROUPS), $codes), []);
        }

        return FreeRoomsDay::of($frame, $availability, $wanted, null !== $now ? new \DateTimeImmutable($now) : null);
    }

    /**
     * A space card with a code and the size the centre confirmed (null for one nobody has measured).
     *
     * @param string        $code the room code
     * @param RoomSize|null $size the confirmed size, or null
     *
     * @return Room the card
     */
    private static function room(string $code, ?RoomSize $size): Room
    {
        return (new Room())->setCode($code)->setName($code)->setSize($size);
    }

    /**
     * The codes of a list of rooms, for readable assertions.
     *
     * @param list<Room> $rooms the rooms
     *
     * @return list<string> their codes
     */
    private static function codesOf(array $rooms): array
    {
        return array_map(static fn (Room $r): string => $r->getCode(), $rooms);
    }
}
