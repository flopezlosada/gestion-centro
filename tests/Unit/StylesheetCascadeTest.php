<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guarda la hoja de estilos contra la avería que tapó el final de todas las pantallas en móvil.
 *
 * app.css tiene VARIOS bloques `@media (max-width: 760px)` repartidos por el fichero, uno por zona de
 * la interfaz. Eso está bien mientras cada selector viva en uno solo: en cuanto el mismo selector se
 * declara en dos bloques con la misma condición, gana el que esté más abajo y el de arriba deja de
 * existir en silencio, a mil líneas de distancia y sin que nada falle.
 *
 * Es como se perdió el hueco de la barra inferior: un bloque reservaba `padding-bottom` para ella y
 * otro, más abajo, declaraba `.page { padding: 22px 16px }` —el shorthand resetea el bottom—, así que
 * la barra tapaba 45px del final del contenido (79 en un iPhone, con la safe-area). Los z-index del
 * cajón "Más" estaban duplicados igual y solo funcionaban porque el orden relativo coincidía.
 *
 * No se comprueba ningún valor concreto a propósito: el valor correcto cambia cuando cambia el diseño.
 * Lo que no puede volver es el PATRÓN.
 */
final class StylesheetCascadeTest extends TestCase
{
    private const STYLESHEET = __DIR__.'/../../public/css/app.css';
    private const BREAKPOINT = '@media (max-width: 760px)';

    public function testNoSelectorIsDeclaredInTwoBlocksOfTheSameBreakpoint(): void
    {
        $duplicates = [];
        foreach ($this->selectorsByBlock() as $selector => $blocks) {
            if (count($blocks) > 1) {
                $duplicates[$selector] = $blocks;
            }
        }

        self::assertSame([], $duplicates, $this->explain($duplicates));
    }

    /**
     * Selectores de primer nivel de cada bloque del breakpoint, con la línea en la que se declaran,
     * agrupados por selector. Solo se miran los que están sangrados con cuatro espacios: son los hijos
     * directos del `@media`, y los anidados más adentro (`.sidebar.is-open .nav`) no compiten entre sí
     * por la misma declaración.
     *
     * @return array<string, list<int>> selector => líneas en las que aparece, una por bloque distinto
     */
    private function selectorsByBlock(): array
    {
        $lines = file(self::STYLESHEET, FILE_IGNORE_NEW_LINES);
        self::assertNotFalse($lines, 'la hoja de estilos se puede leer');

        $found = [];
        foreach ($this->blocks($lines) as [$start, $end]) {
            $seenInThisBlock = [];
            for ($n = $start; $n <= $end; ++$n) {
                if (1 !== preg_match('/^ {4}([.#\w][^{]*?)\s*\{/', $lines[$n], $m)) {
                    continue;
                }
                $selector = trim($m[1]);
                // Un selector repetido DENTRO del mismo bloque es legítimo (añadir una propiedad a
                // continuación de otras); el problema es el mismo selector en bloques distintos.
                if (isset($seenInThisBlock[$selector])) {
                    continue;
                }
                $seenInThisBlock[$selector] = true;
                $found[$selector][] = $n + 1;
            }
        }

        return $found;
    }

    /**
     * Rangos [inicio, fin] de cada bloque del breakpoint, delimitados contando llaves para no
     * confundir un bloque con el siguiente ni cortar en una regla anidada.
     *
     * @param list<string> $lines
     *
     * @return list<array{int, int}> índices de línea base 0
     */
    private function blocks(array $lines): array
    {
        $blocks = [];
        $total = count($lines);
        foreach ($lines as $i => $line) {
            if (!str_contains($line, self::BREAKPOINT)) {
                continue;
            }
            $depth = 0;
            for ($j = $i; $j < $total; ++$j) {
                $depth += substr_count($lines[$j], '{') - substr_count($lines[$j], '}');
                if (0 === $depth && $j > $i) {
                    $blocks[] = [$i, $j];
                    break;
                }
            }
        }

        // Sin aserción sobre cuántos bloques hay: si algún día se unifican en uno, no habrá duplicados
        // posibles y el test pasará solo. Exigir varios castigaría justo la mejora que persigue.
        return $blocks;
    }

    /**
     * @param array<string, list<int>> $duplicates
     */
    private function explain(array $duplicates): string
    {
        if ([] === $duplicates) {
            return '';
        }

        $detail = [];
        foreach ($duplicates as $selector => $lines) {
            $detail[] = sprintf('%s en las líneas %s', $selector, implode(' y ', $lines));
        }

        return sprintf(
            "Estos selectores se declaran en dos bloques '%s' distintos, así que gana el último y el otro no existe: %s. ".
            'Junta las declaraciones en un solo sitio.',
            self::BREAKPOINT,
            implode('; ', $detail)
        );
    }
}
