<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Enum\Weekday;
use App\Penalara\PenalaraMeetingParser;
use PHPUnit\Framework\TestCase;

/**
 * La sección {@code <reuniones>} del planificador de Peñalara, con la forma del export real de 2026-2027:
 * nombre, un tramo fijado con {@code dia} desde 0 = lunes, e integrantes por código de empleado.
 */
final class PenalaraMeetingParserTest extends TestCase
{
    private const XML = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <datosGHC>
            <profesores>
                <profesor><nombreCompleto>Ruiz Gil, Ana</nombreCompleto><claveDeExportacion>111</claveDeExportacion></profesor>
                <profesor><nombreCompleto>Sanz Mora, Luis</nombreCompleto><claveDeExportacion>222</claveDeExportacion></profesor>
            </profesores>
            <reuniones>
                <reunion subMarco="A">
                    <nombre>TUTORES/AS 3º ESO</nombre>
                    <numeroDeReuniones>1</numeroDeReuniones>
                    <tipoDeTarea>SEC-FP - Reuniones de los órganos de coordinación didáctica: Reunión de Departamento</tipoDeTarea>
                    <plantilla><tramo dia="0" indice="7" marco="A">fijado</tramo></plantilla>
                    <integrantes><integrante>111</integrante><integrante>222</integrante><integrante>111</integrante></integrantes>
                </reunion>
                <reunion subMarco="A">
                    <nombre>CCP</nombre>
                    <plantilla><tramo dia="1" indice="2" marco="A">fijado</tramo></plantilla>
                    <integrantes><integrante>222</integrante></integrantes>
                </reunion>
                <reunion subMarco="A">
                    <nombre>DOBLE</nombre>
                    <plantilla><tramo dia="2" indice="1" marco="A">fijado</tramo><tramo dia="3" indice="1" marco="A">fijado</tramo></plantilla>
                    <integrantes><integrante>111</integrante></integrantes>
                </reunion>
                <reunion subMarco="A">
                    <nombre>SIN HORA</nombre>
                    <plantilla></plantilla>
                    <integrantes><integrante>111</integrante></integrantes>
                </reunion>
            </reuniones>
        </datosGHC>
        XML;

    public function testReadsEachWeeklyMeetingWithItsDayPeriodAndMembers(): void
    {
        $meetings = (new PenalaraMeetingParser())->parse(self::XML)['meetings'];

        self::assertCount(2, $meetings);
        self::assertSame('TUTORES/AS 3º ESO', $meetings[0]->name);
        self::assertSame(Weekday::MONDAY, $meetings[0]->weekday, 'dia="0" es el lunes');
        self::assertSame(7, $meetings[0]->slotIndex);
        self::assertSame(['111', '222'], $meetings[0]->memberCodes, 'un integrante repetido cuenta una vez');
        self::assertSame(Weekday::TUESDAY, $meetings[1]->weekday);
        self::assertSame(2, $meetings[1]->slotIndex);
    }

    /** Lo que no es semanal de un solo tramo no se adivina: se devuelve para decirlo. */
    public function testMeetingsNotFixedToExactlyOnePeriodAreReportedNotGuessed(): void
    {
        self::assertSame(['DOBLE', 'SIN HORA'], (new PenalaraMeetingParser())->parse(self::XML)['skipped']);
    }

    /** Los nombres de los profesores, para decir QUIÉN no casa en vez de un número. */
    public function testReturnsTeacherNamesByCode(): void
    {
        $teachers = (new PenalaraMeetingParser())->parse(self::XML)['teachers'];

        self::assertCount(2, $teachers);
        self::assertSame('Ruiz Gil, Ana', $teachers['111']);
        self::assertSame('Sanz Mora, Luis', $teachers['222']);
    }

    public function testRejectsSomethingThatIsNotXml(): void
    {
        $this->expectException(\RuntimeException::class);
        (new PenalaraMeetingParser())->parse('esto no es xml');
    }
}
