<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Family\FamilySessions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La semana que abre el calendario: de lunes a viernes, la de ese día; en fin de semana, la siguiente,
 * que es la que queda por delante (la que acaba de terminar ya no tiene sesiones abiertas).
 */
final class FamilySessionsWeekTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function days(): iterable
    {
        yield 'lunes' => ['2026-01-12 08:00', '2026-01-12'];
        yield 'viernes por la tarde' => ['2026-01-16 18:30', '2026-01-12'];
        yield 'sábado' => ['2026-01-17 10:00', '2026-01-19'];
        yield 'domingo a última hora' => ['2026-01-18 23:59', '2026-01-19'];
    }

    /**
     * Comprueba el lunes de la semana que se abre para cada momento.
     */
    #[DataProvider('days')]
    public function testTheWeekOpenedIsTheOneAhead(string $now, string $monday): void
    {
        self::assertSame($monday, FamilySessions::currentMonday(new \DateTimeImmutable($now))->format('Y-m-d'));
    }
}
