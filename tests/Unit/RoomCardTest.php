<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Room;
use App\Enum\RoomKind;
use PHPUnit\Framework\TestCase;

/**
 * The two rules a space card enforces on its own: a code is stored normalised (so the catalogue cannot
 * grow a second card for the same room), and a card knows when it is still a stub nobody has completed.
 */
final class RoomCardTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function codes(): iterable
    {
        yield 'already normalised' => ['2IN5', '2IN5'];
        yield 'lower case' => ['bibl', 'BIBL'];
        yield 'surrounding spaces' => ['  LABQ ', 'LABQ'];
        yield 'inner double space' => ['S  ACTOS', 'S ACTOS'];
        yield 'tab as separator' => ["PIST\tROJ", 'PIST ROJ'];
    }

    /**
     * @dataProvider codes
     */
    public function testCodeIsStoredNormalised(string $raw, string $expected): void
    {
        self::assertSame($expected, (new Room())->setCode($raw)->getCode());
    }

    public function testTwoSpellingsOfTheSameRoomProduceTheSameCode(): void
    {
        // This is the whole point: the timetable writes "S ACTOS" and a person types "s  actos".
        self::assertSame(
            (new Room())->setCode('S ACTOS')->getCode(),
            (new Room())->setCode('s  actos')->getCode(),
        );
    }

    public function testAFreshCardNeedsReviewUntilItHasKindAndCapacity(): void
    {
        $room = (new Room())->setCode('2IN5')->setName('2IN5');
        self::assertTrue($room->needsReview(), 'a stub the synchroniser created is incomplete');

        $room->setKind(RoomKind::CLASSROOM);
        self::assertTrue($room->needsReview(), 'a kind alone is not enough: capacity is still unknown');

        $room->setCapacity(30);
        self::assertFalse($room->needsReview());
    }

    public function testLabelFallsBackToTheCodeWhileNobodyHasNamedIt(): void
    {
        self::assertSame('2IN5', (new Room())->setCode('2IN5')->setName('2IN5')->getLabel());
        self::assertSame('Aula de Inglés 5 (2IN5)', (new Room())->setCode('2IN5')->setName('Aula de Inglés 5')->getLabel());
    }

    public function testSpecialisedKindsAreTheOnesAnOrdinaryLessonWouldDisplace(): void
    {
        self::assertTrue(RoomKind::LAB->isSpecialised());
        self::assertTrue(RoomKind::LIBRARY->isSpecialised());
        self::assertFalse(RoomKind::CLASSROOM->isSpecialised(), 'an ordinary classroom costs nothing to use');
        self::assertFalse(RoomKind::OTHER->isSpecialised(), 'unclassified must not be treated as precious');
    }
}
