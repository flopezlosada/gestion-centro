<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Util\Excerpt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Shortening a task description for a list cell. The point of the helper is that it never leaves a
 * half word behind, which is what a plain slice did ("…al final de la hor…").
 */
final class ExcerptTest extends TestCase
{
    /**
     * @return iterable<string, array{string|null, int, string}>
     */
    public static function texts(): iterable
    {
        yield 'cabe entero' => ['Terminar la ficha.', 60, 'Terminar la ficha.'];
        yield 'justo en el límite' => ['1234567890', 10, '1234567890'];
        yield 'corta por palabra' => ['Ejercicios de repaso del tema; se recogen al final de la hora.', 40, 'Ejercicios de repaso del tema; se…'];
        yield 'no deja media palabra' => ['aaaa bbbb cccc dddd', 12, 'aaaa bbbb…'];
        yield 'una palabra larguísima se corta en seco' => ['inconstitucionalísimamente', 10, 'inconstit…'];
        yield 'limpia la puntuación del corte' => ['Uno, dos, tres, cuatro', 12, 'Uno, dos…'];
        yield 'recorta los espacios de fuera' => ['   Hola mundo   ', 60, 'Hola mundo'];
        yield 'sin descripción' => [null, 60, ''];
        yield 'cadena vacía' => ['', 60, ''];
    }

    #[DataProvider('texts')]
    public function testItShortensWithoutBreakingWords(?string $text, int $limit, string $expected): void
    {
        $got = Excerpt::of($text, $limit);

        self::assertSame($expected, $got);
        self::assertLessThanOrEqual($limit, mb_strlen($got), 'el resultado nunca pasa del límite pedido');
    }
}
