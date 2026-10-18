<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\FamilyAvailability;
use App\Entity\FamilyVolunteer;
use App\Enum\Weekday;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Una franja de disponibilidad termina después de empezar, se cree o se mueva: la regla vive en la
 * entidad, no solo en el formulario. Se comparan horas y minutos, no la fecha que traigan.
 */
final class FamilyAvailabilityTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function spansThatDoNotEndAfterTheyStart(): iterable
    {
        yield 'misma hora' => ['10:00', '10:00'];
        yield 'al revés' => ['12:00', '09:00'];
        yield 'otro día pero antes' => ['2026-01-01 12:00', '2026-01-02 09:00'];
    }

    #[DataProvider('spansThatDoNotEndAfterTheyStart')]
    public function testASpanMustEndAfterItStarts(string $from, string $until): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new FamilyAvailability(new FamilyVolunteer(), Weekday::MONDAY, new \DateTimeImmutable($from), new \DateTimeImmutable($until));
    }

    public function testAMovedSpanFollowsTheSameRuleAndKeepsItsHoursWhenRefused(): void
    {
        $span = new FamilyAvailability(new FamilyVolunteer(), Weekday::MONDAY, new \DateTimeImmutable('09:00'), new \DateTimeImmutable('12:00'));

        try {
            $span->changeHours(new \DateTimeImmutable('13:00'), new \DateTimeImmutable('11:00'));
            self::fail('A backwards span was accepted.');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame('09:00–12:00', $span->getFrom()->format('H:i').'–'.$span->getUntil()->format('H:i'));
    }
}
