<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Enum\ScheduleActivityKind;
use App\Enum\TimeSlotKind;
use App\Penalara\PenalaraTimetableParser;
use App\Penalara\ScheduleEntryDto;
use App\Penalara\TimeFrameSlotDto;
use PHPUnit\Framework\TestCase;

/**
 * The parser turns a Peñalara planificador (the name dictionary) plus a resolved timetable (SÉNECA)
 * into schedule DTOs: teaching cells named from the dictionary, guardia/collaborator duties detected
 * by their <tarea> code, other complementary activities dropped, and exact duplicates removed.
 *
 * It also reads the marco horario — the shape of the school day — which is the only place the recreos
 * appear at all: no activity is ever scheduled during a break, so a period nobody occupies would be
 * invisible to a parser that only looked at the resolved timetable.
 */
final class PenalaraTimetableParserTest extends TestCase
{
    private const PLANIFICADOR = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <datosGHC>
            <marcosDeHorario>
                <marcoHorario id="A" nombre="M">
                    <tramo><submarco>A</submarco><dia>0</dia><indice>0</indice><horaEntrada>08:25:00</horaEntrada><horaSalida>09:20:00</horaSalida><Tipo>lectivo</Tipo><clavX>1000</clavX></tramo>
                    <tramo><submarco>A</submarco><dia>0</dia><indice>1</indice><horaEntrada>09:20:00</horaEntrada><horaSalida>10:15:00</horaSalida><Tipo>lectivo</Tipo><clavX>1001</clavX></tramo>
                </marcoHorario>
            </marcosDeHorario>
            <profesor>
                <nombre>777</nombre>
                <abreviatura>Doe, J</abreviatura>
                <nombreCompleto>Doe Smith, Jane</nombreCompleto>
                <departamento>Matemáticas</departamento>
                <claveDeExportacion>777</claveDeExportacion>
            </profesor>
            <grupo submarco="A">
                <nombre>E1A</nombre>
                <abreviatura>1ºA</abreviatura>
                <nombreCompleto>E1A~1º ESO A</nombreCompleto>
                <claveDeExportacion>500-900</claveDeExportacion>
            </grupo>
            <aula>
                <nombre>10</nombre>
                <abreviatura>A10</abreviatura>
                <claveDeExportacion>60</claveDeExportacion>
            </aula>
            <materia>
                <nombre>2000</nombre>
                <abreviatura>Matemáticas</abreviatura>
                <nombreCompleto>1º ESO ~ Matemáticas</nombreCompleto>
                <claveDeExportacion>2000</claveDeExportacion>
            </materia>
            <tarea>
                <nombre>Guardias</nombre>
                <nombreCompleto>SEC - Guardias</nombreCompleto>
                <claveDeExportacion>65</claveDeExportacion>
            </tarea>
            <tarea>
                <nombre>Apoyo</nombre>
                <nombreCompleto>SEC - Apoyo a los profesores de guardia</nombreCompleto>
                <claveDeExportacion>76</claveDeExportacion>
            </tarea>
            <tarea>
                <nombre>Reunión</nombre>
                <nombreCompleto>SEC - Reunión de departamento</nombreCompleto>
                <claveDeExportacion>90</claveDeExportacion>
            </tarea>
        </datosGHC>
        XML;

    private const HORARIO = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <SERVICIO modulo="HORARIOS">
            <BLOQUE_DATOS>
                <grupo_datos seq="HORARIOS_REGULARES" registros="1">
                    <grupo_datos seq="HORARIO_REGULAR_PROFESOR_1" registros="5">
                        <dato nombre_dato="X_EMPLEADO">777</dato>
                        <grupo_datos seq="ACTIVIDAD_1">
                            <dato nombre_dato="N_DIASEMANA">2</dato>
                            <dato nombre_dato="X_TRAMO">1001</dato>
                            <dato nombre_dato="X_DEPENDENCIA">60</dato>
                            <dato nombre_dato="X_UNIDAD">900</dato>
                            <dato nombre_dato="X_OFERTAMATRIG">500</dato>
                            <dato nombre_dato="X_MATERIAOMG">2000</dato>
                            <dato nombre_dato="X_ACTIVIDAD">1</dato>
                        </grupo_datos>
                        <grupo_datos seq="ACTIVIDAD_2">
                            <dato nombre_dato="N_DIASEMANA">3</dato>
                            <dato nombre_dato="X_TRAMO">1000</dato>
                            <dato nombre_dato="X_DEPENDENCIA"></dato>
                            <dato nombre_dato="X_UNIDAD"></dato>
                            <dato nombre_dato="X_OFERTAMATRIG"></dato>
                            <dato nombre_dato="X_MATERIAOMG"></dato>
                            <dato nombre_dato="X_ACTIVIDAD">65</dato>
                        </grupo_datos>
                        <grupo_datos seq="ACTIVIDAD_3">
                            <dato nombre_dato="N_DIASEMANA">4</dato>
                            <dato nombre_dato="X_TRAMO">1000</dato>
                            <dato nombre_dato="X_UNIDAD"></dato>
                            <dato nombre_dato="X_ACTIVIDAD">76</dato>
                        </grupo_datos>
                        <grupo_datos seq="ACTIVIDAD_4">
                            <dato nombre_dato="N_DIASEMANA">5</dato>
                            <dato nombre_dato="X_TRAMO">1000</dato>
                            <dato nombre_dato="X_UNIDAD"></dato>
                            <dato nombre_dato="X_ACTIVIDAD">90</dato>
                        </grupo_datos>
                        <grupo_datos seq="ACTIVIDAD_5">
                            <dato nombre_dato="N_DIASEMANA">2</dato>
                            <dato nombre_dato="X_TRAMO">1001</dato>
                            <dato nombre_dato="X_DEPENDENCIA">60</dato>
                            <dato nombre_dato="X_UNIDAD">900</dato>
                            <dato nombre_dato="X_OFERTAMATRIG">500</dato>
                            <dato nombre_dato="X_MATERIAOMG">2000</dato>
                            <dato nombre_dato="X_ACTIVIDAD">1</dato>
                        </grupo_datos>
                    </grupo_datos>
                </grupo_datos>
            </BLOQUE_DATOS>
        </SERVICIO>
        XML;

    public function testParsesLectiveGuardiaAndCollaboratorAndDropsTheRest(): void
    {
        $entries = (new PenalaraTimetableParser())->parse(self::PLANIFICADOR, self::HORARIO)->entries;

        // Lective + guardia + collaborator = 3; the meeting (code 90) is dropped, the duplicate merged.
        self::assertCount(3, $entries);

        $kinds = array_map(static fn (ScheduleEntryDto $e): string => $e->kind->value, $entries);
        sort($kinds);
        self::assertSame(['collaborator', 'guardia', 'lective'], $kinds);
    }

    public function testLectiveEntryResolvesNamesFromDictionary(): void
    {
        $entries = (new PenalaraTimetableParser())->parse(self::PLANIFICADOR, self::HORARIO)->entries;
        $lective = $this->ofKind($entries, ScheduleActivityKind::LECTIVE);

        self::assertSame('Doe Smith, Jane', $lective->teacherName);
        self::assertSame('777', $lective->teacherCode);
        self::assertSame(2, $lective->weekday);
        self::assertSame(1, $lective->slotIndex);
        self::assertSame('09:20:00', $lective->startsAt);
        self::assertSame('1ºA', $lective->groupName);
        self::assertSame('A10', $lective->roomName);
        self::assertSame('Matemáticas', $lective->subjectName);
    }

    public function testGuardiaEntryHasNoGroupRoomOrSubject(): void
    {
        $entries = (new PenalaraTimetableParser())->parse(self::PLANIFICADOR, self::HORARIO)->entries;
        $guardia = $this->ofKind($entries, ScheduleActivityKind::GUARDIA);

        self::assertSame(3, $guardia->weekday);
        self::assertSame(0, $guardia->slotIndex);
        self::assertNull($guardia->groupName);
        self::assertNull($guardia->roomName);
        self::assertNull($guardia->subjectName);
    }

    public function testEmptyHorarioYieldsNoEntries(): void
    {
        $empty = '<?xml version="1.0" encoding="UTF-8"?><SERVICIO><BLOQUE_DATOS/></SERVICIO>';
        self::assertSame([], (new PenalaraTimetableParser())->parse(self::PLANIFICADOR, $empty)->entries);
    }

    public function testMalformedXmlThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        (new PenalaraTimetableParser())->parse('<not-closed', self::HORARIO);
    }

    public function testTimeFrameCollapsesTheWeekIntoOnePeriodPerIndex(): void
    {
        // The export repeats every period once per weekday; two weekdays of two periods must still yield
        // two periods, not four.
        $frame = (new PenalaraTimetableParser())->parse(self::PLANIFICADOR, self::HORARIO)->frame;

        self::assertCount(2, $frame);
        self::assertSame([0, 1], array_map(static fn (TimeFrameSlotDto $s): int => $s->index, $frame));
        self::assertSame('08:25:00', $frame[0]->startsAt);
        self::assertSame('09:20:00', $frame[0]->endsAt);
    }

    public function testBreakPeriodsAreRecognisedFromTheirType(): void
    {
        // The whole point of reading the frame: the recreo is a period NOBODY has an activity in, so it
        // exists only here. Times and type mirror the centre's real export (11:10–11:35).
        $frame = (new PenalaraTimetableParser())->parse($this->planificadorWithBreak('recreo'), self::HORARIO)->frame;

        $breaks = array_values(array_filter($frame, static fn (TimeFrameSlotDto $s): bool => TimeSlotKind::BREAK_TIME === $s->kind));
        self::assertCount(1, $breaks);
        self::assertSame(2, $breaks[0]->index);
        self::assertSame('11:10:00', $breaks[0]->startsAt);
        self::assertSame('11:35:00', $breaks[0]->endsAt);
    }

    public function testAnUnknownPeriodTypeIsTreatedAsTeachingTime(): void
    {
        // Never the other way round: a period we cannot classify must not silently become a recreo and
        // start demanding a zone rota.
        $frame = (new PenalaraTimetableParser())->parse($this->planificadorWithBreak('complementario'), self::HORARIO)->frame;

        foreach ($frame as $slot) {
            self::assertSame(TimeSlotKind::LECTIVE, $slot->kind);
        }
    }

    public function testAPeriodDefinedTwoWaysIsReportedInsteadOfSilentlyPickingOne(): void
    {
        // Same index, different times on another weekday: the first definition is kept and the index is
        // reported, so the import screen can say so.
        $plan = str_replace(
            '<tramo><submarco>A</submarco><dia>0</dia><indice>1</indice><horaEntrada>09:20:00</horaEntrada><horaSalida>10:15:00</horaSalida><Tipo>lectivo</Tipo><clavX>1001</clavX></tramo>',
            '<tramo><submarco>A</submarco><dia>0</dia><indice>1</indice><horaEntrada>09:20:00</horaEntrada><horaSalida>10:15:00</horaSalida><Tipo>lectivo</Tipo><clavX>1001</clavX></tramo>'
            .'<tramo><submarco>A</submarco><dia>4</dia><indice>1</indice><horaEntrada>09:30:00</horaEntrada><horaSalida>10:15:00</horaSalida><Tipo>lectivo</Tipo><clavX>1002</clavX></tramo>',
            self::PLANIFICADOR,
        );

        $parsed = (new PenalaraTimetableParser())->parse($plan, self::HORARIO);

        self::assertSame([1], $parsed->frameConflicts);
        self::assertCount(2, $parsed->frame);
        self::assertSame('09:20:00', $parsed->frame[1]->startsAt);
    }

    /**
     * The planificador with a third period carrying the given Peñalara type, to exercise the recreo
     * detection without touching the shared fixture.
     *
     * @param string $type the raw {@code <Tipo>} of the extra period
     *
     * @return string the planificador XML
     */
    private function planificadorWithBreak(string $type): string
    {
        return str_replace(
            '</marcoHorario>',
            sprintf('<tramo><submarco>A</submarco><dia>0</dia><indice>2</indice><horaEntrada>11:10:00</horaEntrada><horaSalida>11:35:00</horaSalida><Tipo>%s</Tipo><clavX>1002</clavX></tramo></marcoHorario>', $type),
            self::PLANIFICADOR,
        );
    }

    /**
     * Returns the single entry of the given kind, failing the test if it is not unique.
     *
     * @param list<ScheduleEntryDto> $entries the parsed entries
     * @param ScheduleActivityKind   $kind    the kind to isolate
     *
     * @return ScheduleEntryDto the matching entry
     */
    private function ofKind(array $entries, ScheduleActivityKind $kind): ScheduleEntryDto
    {
        $matches = array_values(array_filter($entries, static fn (ScheduleEntryDto $e): bool => $e->kind === $kind));
        self::assertCount(1, $matches, sprintf('expected exactly one %s entry', $kind->value));

        return $matches[0];
    }
}
