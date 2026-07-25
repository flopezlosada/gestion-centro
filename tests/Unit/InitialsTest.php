<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Util\Initials;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The avatar monogram. The cases below are the ones the real roster actually produces: two-word names,
 * a single word, compound surnames with particles, accents, and the null/blank a role without holder
 * leaves behind.
 */
final class InitialsTest extends TestCase
{
    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function names(): iterable
    {
        yield 'nombre y apellido' => ['Mercedes Alende', 'MA'];
        yield 'una sola palabra' => ['Ana', 'A'];
        yield 'se corta en dos letras' => ['María de la Torre', 'MD'];
        yield 'respeta los acentos' => ['Ángela Pérez', 'ÁP'];
        yield 'ya en minúsculas' => ['juan gómez', 'JG'];
        yield 'espacios de sobra' => ['  Juan   Pérez  ', 'JP'];
        yield 'sin titular' => [null, ''];
        yield 'cadena vacía' => ['', ''];
        yield 'solo espacios' => ['   ', ''];
    }

    #[DataProvider('names')]
    public function testItBuildsTheMonogram(?string $fullName, string $expected): void
    {
        self::assertSame($expected, Initials::of($fullName));
    }
}
