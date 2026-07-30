<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Entity\AcademicYear;
use App\Entity\ScheduleEntry;
use App\Entity\TimeSlot;
use App\Entity\User;
use App\Enum\ScheduleActivityKind;
use App\Enum\ScheduleEntrySource;
use App\Enum\Weekday;
use App\Penalara\PenalaraTimetableParser;
use App\Penalara\ScheduleEntryDto;
use App\Penalara\TimeFrameSlotDto;
use App\Repository\ScheduleEntryRepository;
use App\Repository\TimeSlotRepository;
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
 * Hand-marked guardias survive the import, because the export is not the only writer of this table:
 * a cell stamped {@see ScheduleEntrySource::MANUAL} is neither deleted nor written over, and the
 * export cell that would have landed on it is skipped so the teacher is not counted twice in the
 * guardia pool. The one exception is a new LESSON on that period — a person cannot be on guardia while
 * teaching, so there the timetable wins and the hand-marked cell is dropped. Both are reported.
 *
 * Besides the cells, an import records the course's marco horario ({@see TimeSlot}): the periods of the
 * day with their times and which of them are recreos. That part is pure export data with no hand-edited
 * counterpart, so it is simply rebuilt — but only when the export carries one, since losing it would
 * leave the break duty rota with no recreo to show.
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
        private readonly TimeSlotRepository $timeSlots,
        private readonly PenalaraTimetableParser $parser,
    ) {
        $this->slugger = new AsciiSlugger();
    }

    /**
     * Turns the export's marco horario into {@see TimeSlot} rows for the course — the day's periods with
     * their times and, crucially, which of them are recreos. Built even on a dry run so the preview can
     * report what the import would record, and persisted only on the real one.
     *
     * @param AcademicYear           $year  the course the frame belongs to
     * @param list<TimeFrameSlotDto> $frame the parsed periods
     *
     * @return list<TimeSlot> the periods to persist, earliest first
     */
    private function buildFrame(AcademicYear $year, array $frame): array
    {
        return array_map(
            static fn (TimeFrameSlotDto $slot): TimeSlot => (new TimeSlot())
                ->setAcademicYear($year)
                ->setSlotIndex($slot->index)
                ->setStartsAt(new \DateTimeImmutable($slot->startsAt))
                ->setEndsAt(new \DateTimeImmutable($slot->endsAt))
                ->setKind($slot->kind),
            $frame,
        );
    }

    /**
     * Parses the two exports and imports the resolved timetable into the given course.
     *
     * @param AcademicYear $year            the target course; entries are tied to it and replaced within it
     * @param string       $planificadorXml the planificador export (datosGHC), the name dictionary
     * @param string       $horarioXml      the resolved timetable export (SÉNECA SERVICIO)
     * @param bool         $dryRun          when true, analyses and reports without writing to the database
     *
     * @return TimetableImportResult the counts, what is being replaced and what needs a human decision
     *
     * @throws \RuntimeException if either document cannot be parsed as XML
     */
    public function import(AcademicYear $year, string $planificadorXml, string $horarioXml, bool $dryRun = false): TimetableImportResult
    {
        $parsed = $this->parser->parse($planificadorXml, $horarioXml);
        $byTeacher = $this->groupByTeacher($parsed->entries);
        [$matched, $unmatched] = $this->reconcile($byTeacher, $dryRun);

        // Read before writing: what is already there is what the report compares the export against.
        $teachers = array_values($matched);
        $replaced = $this->schedule->countImportedFor($year, $teachers);
        $stale = $this->staleTeachers($year, $matched);

        [$entries, $keptManual, $dropManualIds] = $this->buildEntries($year, $byTeacher, $matched, $this->schedule->protectedDutyCells($year));
        $frame = $this->buildFrame($year, $parsed->frame);
        if (!$dryRun) {
            $this->schedule->replaceForTeachers($year, $teachers, $entries, $dropManualIds);
            // Only when the export actually carried a marco horario: a planificador without one must not
            // wipe the day's shape, which is what the break duty rota reads its times from.
            if ([] !== $frame) {
                $this->timeSlots->replaceForYear($year, $frame);
            }
        }

        $guardias = \count(array_filter($entries, static fn (ScheduleEntry $e): bool => ScheduleActivityKind::LECTIVE !== $e->getKind()));

        return new TimetableImportResult(
            \count($entries),
            $guardias,
            \count($matched),
            $unmatched,
            $dryRun,
            $replaced,
            $keptManual,
            \count($dropManualIds),
            $stale,
            \count($frame),
            \count(array_filter($frame, static fn (TimeSlot $s): bool => $s->isBreak())),
            $parsed->frameConflicts,
        );
    }

    /**
     * The teachers who hold a timetable in this course that the export does not re-import. An import
     * only ever touches the teachers it resolved, so someone who left the centre mid-course keeps their
     * cells — and their guardia slots keep feeding the assignment engine. Nothing is deleted on a guess:
     * they are listed so a person decides.
     *
     * @param AcademicYear        $year    the target course
     * @param array<string, User> $matched the teachers the export resolved to
     *
     * @return list<string> their full names, alphabetically
     */
    private function staleTeachers(AcademicYear $year, array $matched): array
    {
        $matchedIds = array_flip(array_map(static fn (User $u): int => (int) $u->getId(), $matched));

        return array_values(array_map(
            static fn (User $u): string => $u->getFullName(),
            array_filter(
                $this->schedule->teachersWithEntries($year),
                static fn (User $u): bool => !isset($matchedIds[$u->getId()]),
            ),
        ));
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
     * A dry run resolves exactly the same but records nothing: it must not leave a managed entity dirty
     * for whatever flushes next, or previewing an import would half-apply it.
     *
     * @param array<string, array{name: string, dtos: list<ScheduleEntryDto>}> $byTeacher the teachers to resolve
     * @param bool                                                             $dryRun    when true, the matched code is not written back
     *
     * @return array{0: array<string, User>, 1: array<string, string>} [matched code→user, unmatched code→name]
     */
    private function reconcile(array $byTeacher, bool $dryRun): array
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
                if (!$dryRun) {
                    // PHP coerces numeric string array keys to int; cast back for the string column.
                    $user->setPenalaraCode((string) $code);
                }
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
     * Builds the {@see ScheduleEntry} rows for the reconciled teachers, tied to the target course, and
     * settles every clash with a hand-marked cell: an imported DUTY on a period somebody already marked
     * by hand is skipped (the person's cell stands, and the teacher is not listed twice in the pool),
     * while an imported LESSON wins and marks that cell for deletion — nobody can be on guardia and
     * teaching at once.
     *
     * @param AcademicYear                                                     $year      the course the entries belong to
     * @param array<string, array{name: string, dtos: list<ScheduleEntryDto>}> $byTeacher the parsed teachers
     * @param array<string, User>                                              $matched   resolved code → user
     * @param array<int, array<string, int>>                                   $manual    hand-marked cells, teacher id → "weekday:slot" → row id
     *
     * @return array{0: list<ScheduleEntry>, 1: int, 2: list<int>} the entries to persist, how many export
     *                                                             cells the hand-marked ones displaced, and the ids to delete
     */
    private function buildEntries(AcademicYear $year, array $byTeacher, array $matched, array $manual): array
    {
        $entries = [];
        $keptManual = 0;
        $dropManualIds = [];

        foreach ($matched as $code => $user) {
            $owned = $manual[$user->getId()] ?? [];
            foreach ($byTeacher[$code]['dtos'] as $dto) {
                $manualId = $owned[$dto->weekday.':'.$dto->slotIndex] ?? null;
                if (null !== $manualId) {
                    if (ScheduleActivityKind::LECTIVE !== $dto->kind) {
                        ++$keptManual;
                        continue;
                    }
                    $dropManualIds[] = $manualId;
                }

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
                    ->setSubjectName($dto->subjectName)
                    // Explicit, not left to the entity default: this is the writer the flag exists for.
                    ->setSource(ScheduleEntrySource::PENALARA);
            }
        }

        return [$entries, $keptManual, array_values(array_unique($dropManualIds))];
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
