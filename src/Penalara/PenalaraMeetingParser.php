<?php

declare(strict_types=1);

namespace App\Penalara;

use App\Enum\Weekday;

/**
 * Reads the standing weekly meetings out of a Peñalara planificador ("planificador.xml", a
 * {@code datosGHC} document): its {@code <reuniones>} section, one {@code <reunion>} per meeting with
 * its name, the period it is fixed to ({@code <plantilla><tramo dia indice>}) and its
 * {@code <integrantes>}.
 *
 * The timetable import never read this section — the resolved timetable only carries department
 * meetings, as an anonymous duty it drops — which is why the tutor meetings, the CCP and the rest were
 * nowhere in the application although the centre had them all in Peñalara.
 *
 * {@code dia} is 0-based from Monday and {@code indice} is the same period ordinal as the marco horario:
 * checked against the 2026-2027 export, where all 213 member slots match the members' own timetable.
 * The {@code tipoDeTarea} is ignored on purpose: the centre files every meeting under "Reunión de
 * Departamento", tutor meetings and the CCP included, so it says nothing.
 *
 * A meeting fixed to more than one period a week, or to none, is not a weekly meeting this model can
 * hold; it is reported back instead of guessed at.
 *
 * Pure and framework-free so it can be unit-tested on a small synthetic fixture.
 */
final class PenalaraMeetingParser
{
    /**
     * Parses the weekly meetings of a planificador.
     *
     * @param string $planificadorXml the planificador export (datosGHC)
     *
     * @return array{meetings: list<PenalaraMeetingDto>, skipped: list<string>, teachers: array<array-key, string>} the meetings, the names of those not fixed to exactly one weekday period, and every teacher's name by code (to report a member nobody matches by name, not by a bare number; PHP turns numeric codes into int keys, looked up the same with a string)
     *
     * @throws \RuntimeException if the document cannot be parsed as XML
     */
    public function parse(string $planificadorXml): array
    {
        $previous = libxml_use_internal_errors(true);
        $root = simplexml_load_string($planificadorXml);
        libxml_use_internal_errors($previous);
        if (false === $root) {
            throw new \RuntimeException('El fichero del planificador no es un XML válido.');
        }

        $meetings = [];
        $skipped = [];
        foreach ($root->xpath('//reuniones/reunion') ?: [] as $reunion) {
            $name = trim((string) $reunion->nombre);
            if ('' === $name) {
                continue;
            }

            $slots = $reunion->xpath('plantilla/tramo') ?: [];
            $weekday = 1 === \count($slots) ? Weekday::tryFrom((int) $slots[0]['dia'] + 1) : null;
            if (null === $weekday || !\in_array($weekday, Weekday::schoolWeek(), true)) {
                $skipped[] = $name;
                continue;
            }

            $meetings[] = new PenalaraMeetingDto(
                $name,
                $weekday,
                (int) $slots[0]['indice'],
                array_values(array_unique(array_filter(array_map(
                    static fn (\SimpleXMLElement $member): string => trim((string) $member),
                    $reunion->xpath('integrantes/integrante') ?: [],
                ), static fn (string $code): bool => '' !== $code))),
            );
        }

        $teachers = [];
        foreach ($root->xpath('//profesor[claveDeExportacion]') ?: [] as $teacher) {
            $teachers[trim((string) $teacher->claveDeExportacion)] = trim((string) $teacher->nombreCompleto);
        }

        return ['meetings' => $meetings, 'skipped' => $skipped, 'teachers' => $teachers];
    }
}
