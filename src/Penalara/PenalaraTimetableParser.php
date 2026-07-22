<?php

declare(strict_types=1);

namespace App\Penalara;

use App\Enum\ScheduleActivityKind;

/**
 * Turns a pair of Peñalara GHC exports into a flat list of {@see ScheduleEntryDto}: the resolved
 * timetable ("Horario.xml", a SÉNECA {@code SERVICIO}/{@code grupo_datos} document keyed by numeric
 * codes) plus the planificador ("planificador.xml", a {@code datosGHC} document) used purely as the
 * dictionary that turns those codes into human-readable names.
 *
 * How a guardia is recognised: in the resolved timetable a guardia row has no group, subject or room
 * and carries an {@code X_ACTIVIDAD} equal to the planificador export-code of a "materia" whose name
 * marks it as a guardia (or, for collaborator/support duty, "apoyo"/"convivencia"). The codes are
 * resolved by name here — never hard-coded — because the numeric value (65 in the sample) is a
 * per-centre, per-year local key. Everything with a group is teaching; any other duty (meetings,
 * irregular activities) is ignored, being neither coverable nor a covering duty.
 *
 * Pure and framework-free so it can be unit-tested on a small synthetic fixture.
 */
final class PenalaraTimetableParser
{
    /**
     * Parses the two exports into schedule-entry DTOs, exact duplicates removed.
     *
     * @param string $planificadorXml the planificador export (datosGHC), the name dictionary
     * @param string $horarioXml      the resolved timetable export (SÉNECA SERVICIO)
     *
     * @return list<ScheduleEntryDto> one DTO per timetable cell (lective, guardia or collaborator)
     *
     * @throws \RuntimeException if either document cannot be parsed as XML
     */
    public function parse(string $planificadorXml, string $horarioXml): array
    {
        $dict = $this->buildDictionary($this->loadXml($planificadorXml, 'planificador'));

        return $this->parseHorario($this->loadXml($horarioXml, 'horario'), $dict);
    }

    /**
     * Loads an XML string into a SimpleXML element, converting libxml failures into a clear exception.
     *
     * @param string $xml   the raw XML (ISO-8859-1 declared; libxml decodes to UTF-8)
     * @param string $label short name of the document, for the error message
     *
     * @return \SimpleXMLElement the parsed root element
     *
     * @throws \RuntimeException on malformed XML
     */
    private function loadXml(string $xml, string $label): \SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $root = simplexml_load_string($xml);
        libxml_use_internal_errors($previous);

        if (false === $root) {
            throw new \RuntimeException(sprintf('El fichero de %s no es un XML válido.', $label));
        }

        return $root;
    }

    /**
     * Builds the name dictionary from the planificador: teachers, groups, rooms, subjects, time slots
     * and the export-codes that mark guardia and collaborator duties.
     *
     * @param \SimpleXMLElement $plan the planificador root (datosGHC)
     *
     * @return array{
     *     teachers: array<string, string>,
     *     groups: array<string, string>,
     *     rooms: array<string, string>,
     *     subjects: array<string, string>,
     *     slots: array<string, array{index: int, start: string, end: string}>,
     *     guardiaCodes: array<string, true>,
     *     collaboratorCodes: array<string, true>
     * } the dictionary
     */
    private function buildDictionary(\SimpleXMLElement $plan): array
    {
        $teachers = [];
        foreach ($plan->xpath('//profesor[claveDeExportacion]') ?: [] as $p) {
            $teachers[(string) $p->claveDeExportacion] = trim((string) $p->nombreCompleto);
        }

        $groups = [];
        foreach ($plan->xpath('//grupo[claveDeExportacion]') ?: [] as $g) {
            $groups[(string) $g->claveDeExportacion] = trim((string) $g->abreviatura);
        }

        $rooms = [];
        foreach ($plan->xpath('//aula[claveDeExportacion]') ?: [] as $a) {
            $rooms[(string) $a->claveDeExportacion] = trim((string) $a->abreviatura);
        }

        $subjects = [];
        foreach ($plan->xpath('//materia[claveDeExportacion]') ?: [] as $m) {
            $subjects[(string) $m->claveDeExportacion] = trim((string) $m->abreviatura);
        }

        // Guardia and collaborator duties are non-teaching activities, modelled in the planificador as
        // <tarea> elements (not <materia>); the resolved timetable's X_ACTIVIDAD references their code.
        $guardiaCodes = [];
        $collaboratorCodes = [];
        foreach ($plan->xpath('//tarea[claveDeExportacion]') ?: [] as $t) {
            $code = (string) $t->claveDeExportacion;
            $name = mb_strtolower(trim((string) $t->nombreCompleto).' '.trim((string) $t->nombre));
            // Order matters: "Apoyo a los profesores de guardia" contains "guardia" too, so collaborator
            // (apoyo/convivencia) must be tested before the plain guardia case.
            if (str_contains($name, 'apoyo') || str_contains($name, 'convivencia')) {
                $collaboratorCodes[$code] = true;
            } elseif (str_contains($name, 'guardia')) {
                $guardiaCodes[$code] = true;
            }
        }

        $slots = [];
        foreach ($plan->xpath('//marcoHorario/tramo') ?: [] as $t) {
            $slots[(string) $t->clavX] = [
                'index' => (int) $t->indice,
                'start' => trim((string) $t->horaEntrada),
                'end' => trim((string) $t->horaSalida),
            ];
        }

        return [
            'teachers' => $teachers,
            'groups' => $groups,
            'rooms' => $rooms,
            'subjects' => $subjects,
            'slots' => $slots,
            'guardiaCodes' => $guardiaCodes,
            'collaboratorCodes' => $collaboratorCodes,
        ];
    }

    /**
     * Walks the resolved timetable's per-teacher activities and maps each to a DTO, using the
     * dictionary to name the codes and to tell guardia/collaborator duties from teaching.
     *
     * @param \SimpleXMLElement                                                                                                                                                                                                                                                       $hor  the resolved timetable root (SERVICIO)
     * @param array{teachers: array<string, string>, groups: array<string, string>, rooms: array<string, string>, subjects: array<string, string>, slots: array<string, array{index: int, start: string, end: string}>, guardiaCodes: array<string, true>, collaboratorCodes: array<string, true>} $dict the name dictionary
     *
     * @return list<ScheduleEntryDto> the parsed cells, exact duplicates removed
     */
    private function parseHorario(\SimpleXMLElement $hor, array $dict): array
    {
        $entries = [];
        $seen = [];

        foreach ($hor->xpath('//grupo_datos[starts-with(@seq, "HORARIO_REGULAR_PROFESOR_")]') ?: [] as $prof) {
            $teacherCode = $this->dato($prof, 'X_EMPLEADO');
            if (null === $teacherCode) {
                continue;
            }
            $teacherName = $dict['teachers'][$teacherCode] ?? $teacherCode;

            foreach ($prof->xpath('grupo_datos[starts-with(@seq, "ACTIVIDAD_")]') ?: [] as $act) {
                $entry = $this->activityToDto($act, $teacherCode, $teacherName, $dict);
                if (null === $entry) {
                    continue;
                }
                $key = $entry->dedupeKey();
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Maps one ACTIVIDAD node to a DTO, or null when it is neither teaching nor a guardia/collaborator
     * duty (e.g. a meeting) or its time slot is unknown.
     *
     * @param \SimpleXMLElement                                                                                                                                                                                                                                                       $act         the ACTIVIDAD node
     * @param string                                                                                                                                                                                                                                                                  $teacherCode the owning teacher's code
     * @param string                                                                                                                                                                                                                                                                  $teacherName the owning teacher's name
     * @param array{teachers: array<string, string>, groups: array<string, string>, rooms: array<string, string>, subjects: array<string, string>, slots: array<string, array{index: int, start: string, end: string}>, guardiaCodes: array<string, true>, collaboratorCodes: array<string, true>} $dict        the name dictionary
     *
     * @return ScheduleEntryDto|null the mapped cell, or null to skip
     */
    private function activityToDto(\SimpleXMLElement $act, string $teacherCode, string $teacherName, array $dict): ?ScheduleEntryDto
    {
        $weekday = (int) ($this->dato($act, 'N_DIASEMANA') ?? '0');
        if ($weekday < 1 || $weekday > 7) {
            return null;
        }

        $tramo = $this->dato($act, 'X_TRAMO');
        if (null === $tramo || !isset($dict['slots'][$tramo])) {
            return null;
        }
        $slot = $dict['slots'][$tramo];

        $activityCode = $this->dato($act, 'X_ACTIVIDAD');
        $unit = $this->dato($act, 'X_UNIDAD');

        $kind = match (true) {
            null !== $activityCode && isset($dict['guardiaCodes'][$activityCode]) => ScheduleActivityKind::GUARDIA,
            null !== $activityCode && isset($dict['collaboratorCodes'][$activityCode]) => ScheduleActivityKind::COLLABORATOR,
            null !== $unit => ScheduleActivityKind::LECTIVE,
            default => null,
        };
        if (null === $kind) {
            return null;
        }

        if (ScheduleActivityKind::LECTIVE === $kind) {
            $groupKey = ($this->dato($act, 'X_OFERTAMATRIG') ?? '').'-'.$unit;
            $subject = $this->dato($act, 'X_MATERIAOMG');
            $room = $this->dato($act, 'X_DEPENDENCIA');

            return new ScheduleEntryDto(
                $teacherCode,
                $teacherName,
                $weekday,
                $slot['index'],
                $slot['start'],
                $slot['end'],
                $kind,
                $dict['groups'][$groupKey] ?? $unit,
                null !== $room ? ($dict['rooms'][$room] ?? $room) : null,
                null !== $subject ? ($dict['subjects'][$subject] ?? null) : null,
            );
        }

        return new ScheduleEntryDto(
            $teacherCode,
            $teacherName,
            $weekday,
            $slot['index'],
            $slot['start'],
            $slot['end'],
            $kind,
        );
    }

    /**
     * Reads the value of a direct-child {@code <dato nombre_dato="...">} of a node, or null when the
     * element is absent or empty (guardia rows carry empty group/subject/room datos).
     *
     * @param \SimpleXMLElement $node the container node
     * @param string            $name the {@code nombre_dato} to read
     *
     * @return string|null the trimmed value, or null when absent/empty
     */
    private function dato(\SimpleXMLElement $node, string $name): ?string
    {
        $found = $node->xpath('dato[@nombre_dato="'.$name.'"]');
        if (!$found) {
            return null;
        }
        $value = trim((string) $found[0]);

        return '' === $value ? null : $value;
    }
}
