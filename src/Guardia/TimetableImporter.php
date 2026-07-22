<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Entity\AcademicYear;
use App\Entity\ScheduleEntry;
use App\Entity\User;
use App\Enum\ScheduleActivityKind;
use App\Enum\Weekday;
use App\Penalara\PenalaraTimetableParser;
use App\Penalara\ScheduleEntryDto;
use App\Repository\ScheduleEntryRepository;
use App\Repository\UserRepository;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Imports a pair of Peñalara GHC exports into {@see ScheduleEntry} rows for a given course, reconciling
 * each Peñalara teacher to a {@see User} and replacing only that course's entries for the reconciled
 * teachers (so a re-import of the same course is idempotent and other courses are untouched).
 *
 * Reconciliation is the crux: the export identifies teachers by a numeric Peñalara code, not by the
 * e-mail our people are keyed on. A teacher is matched by their stored {@see User::$penalaraCode}
 * first and, failing that, by name (accent-insensitive, order-insensitive token set — the export's
 * "Apellidos, Nombre" still matches our "Nombre Apellidos"); a unique name match records the code so
 * every later import re-links without re-matching. Teachers that match nobody are reported and their
 * schedule is skipped until someone reconciles them — never guessed.
 *
 * Shared by {@see \App\Command\ImportTimetableCommand} (CLI) and the admin self-service screen, so the
 * matching and persistence live here once rather than in each entry point.
 */
final class TimetableImporter
{
    private readonly AsciiSlugger $slugger;

    public function __construct(
        private readonly UserRepository $users,
        private readonly ScheduleEntryRepository $schedule,
        private readonly PenalaraTimetableParser $parser,
    ) {
        $this->slugger = new AsciiSlugger();
    }

    /**
     * Parses the two exports and imports the resolved timetable into the given course.
     *
     * @param AcademicYear $year            the target course; entries are tied to it and replaced within it
     * @param string       $planificadorXml the planificador export (datosGHC), the name dictionary
     * @param string       $horarioXml      the resolved timetable export (SÉNECA SERVICIO)
     * @param bool         $dryRun          when true, analyses and reports without writing to the database
     *
     * @return TimetableImportResult the counts and the teachers left unmatched
     *
     * @throws \RuntimeException if either document cannot be parsed as XML
     */
    public function import(AcademicYear $year, string $planificadorXml, string $horarioXml, bool $dryRun = false): TimetableImportResult
    {
        $byTeacher = $this->groupByTeacher($this->parser->parse($planificadorXml, $horarioXml));
        [$matched, $unmatched] = $this->reconcile($byTeacher);

        $entries = $this->buildEntries($year, $byTeacher, $matched);
        if (!$dryRun) {
            $this->schedule->replaceForTeachers($year, array_values($matched), $entries);
        }

        $guardias = \count(array_filter($entries, static fn (ScheduleEntry $e): bool => ScheduleActivityKind::LECTIVE !== $e->getKind()));

        return new TimetableImportResult(\count($entries), $guardias, \count($matched), $unmatched, $dryRun);
    }

    /**
     * Groups the parsed entries by their Peñalara teacher code.
     *
     * @param list<ScheduleEntryDto> $dtos the parsed entries
     *
     * @return array<string, array{name: string, dtos: list<ScheduleEntryDto>}> map code → name + entries
     */
    private function groupByTeacher(array $dtos): array
    {
        $byTeacher = [];
        foreach ($dtos as $dto) {
            $byTeacher[$dto->teacherCode]['name'] = $dto->teacherName;
            $byTeacher[$dto->teacherCode]['dtos'][] = $dto;
        }

        return $byTeacher;
    }

    /**
     * Resolves each Peñalara teacher to a {@see User}: by stored code, else by a unique name match
     * (recording the code so later runs skip the matching). Users already linked to another code, and
     * ambiguous or absent name matches, are left unmatched.
     *
     * @param array<string, array{name: string, dtos: list<ScheduleEntryDto>}> $byTeacher the teachers to resolve
     *
     * @return array{0: array<string, User>, 1: array<string, string>} [matched code→user, unmatched code→name]
     */
    private function reconcile(array $byTeacher): array
    {
        $byCode = [];
        $freeByName = [];
        foreach ($this->users->findAll() as $user) {
            if (null !== $user->getPenalaraCode()) {
                $byCode[$user->getPenalaraCode()] = $user;
            } else {
                $freeByName[$this->nameKey($user->getFullName())][] = $user;
            }
        }

        $matched = [];
        $unmatched = [];
        foreach ($byTeacher as $code => $teacher) {
            if (isset($byCode[$code])) {
                $matched[$code] = $byCode[$code];
                continue;
            }

            $candidates = $freeByName[$this->nameKey($teacher['name'])] ?? [];
            if (1 === \count($candidates)) {
                $user = $candidates[0];
                // PHP coerces numeric string array keys to int; cast back for the string column.
                $user->setPenalaraCode((string) $code);
                $matched[$code] = $user;
                // Claim the user so a second same-named teacher in this run cannot grab them too.
                $freeByName[$this->nameKey($teacher['name'])] = [];
                continue;
            }

            $unmatched[$code] = $teacher['name'];
        }

        return [$matched, $unmatched];
    }

    /**
     * Builds the {@see ScheduleEntry} rows for the reconciled teachers, tied to the target course.
     *
     * @param AcademicYear                                                     $year      the course the entries belong to
     * @param array<string, array{name: string, dtos: list<ScheduleEntryDto>}> $byTeacher the parsed teachers
     * @param array<string, User>                                              $matched   resolved code → user
     *
     * @return list<ScheduleEntry> the entries to persist
     */
    private function buildEntries(AcademicYear $year, array $byTeacher, array $matched): array
    {
        $entries = [];
        foreach ($matched as $code => $user) {
            foreach ($byTeacher[$code]['dtos'] as $dto) {
                $entries[] = (new ScheduleEntry())
                    ->setAcademicYear($year)
                    ->setTeacher($user)
                    ->setWeekday(Weekday::from($dto->weekday))
                    ->setSlotIndex($dto->slotIndex)
                    ->setStartsAt(new \DateTimeImmutable($dto->startsAt))
                    ->setEndsAt(new \DateTimeImmutable($dto->endsAt))
                    ->setKind($dto->kind)
                    ->setGroupName($dto->groupName)
                    ->setRoomName($dto->roomName)
                    ->setSubjectName($dto->subjectName);
            }
        }

        return $entries;
    }

    /**
     * A normalised, order-independent key for a person's name: accent-stripped, lower-cased tokens
     * sorted alphabetically. Makes the export's "Apellidos, Nombre" match our "Nombre Apellidos".
     *
     * @param string $name the full name
     *
     * @return string the comparison key
     */
    private function nameKey(string $name): string
    {
        $ascii = $this->slugger->slug($name, ' ')->lower()->toString();
        $tokens = array_values(array_filter(explode(' ', $ascii), static fn (string $t): bool => '' !== $t));
        sort($tokens);

        return implode(' ', $tokens);
    }
}
