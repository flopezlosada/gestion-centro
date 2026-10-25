<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Entity\AcademicYear;
use App\Entity\ScheduleEntry;
use App\Entity\Substitution;
use App\Entity\TimeSlot;
use App\Entity\User;
use App\Enum\ScheduleActivityKind;
use App\Enum\ScheduleEntrySource;
use App\Enum\Weekday;
use App\Penalara\PenalaraTimetableParser;
use App\Penalara\ScheduleEntryDto;
use App\Penalara\TimeFrameSlotDto;
use App\Repository\ScheduleEntryRepository;
use App\Repository\SubstitutionRepository;
use App\Repository\TimeSlotRepository;
use App\Repository\UserRepository;
use App\Space\RoomSynchroniser;
use Doctrine\ORM\EntityManagerInterface;
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
 * But the code names a post, not a person: Peñalara gives the code of someone on leave to their
 * substitute. So a stored code only counts while its holder's name still fits the export's; when it
 * does not, the name decides, the code moves to the person the export names, and the old holder loses
 * the imported cells of that post. See {@see reconcile()}.
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
 *
 * Its last step (never on a dry run) hands over to {@see RoomSynchroniser}: the cells name rooms, and
 * the space module can only reason about a room it has a card for. Discovering spaces belongs to that
 * module, not here — this just makes sure it happens whenever the timetable changes.
 *
 * Y toda la escritura va envuelta en {@see SubstitutionApplier::withSubstitutionsSuspended()}. Sin eso,
 * reimportar con una baja larga en curso NO pierde la sustitución: la duplica. Las filas están a nombre
 * de quien sustituye, así que el borrado por "profesor IN (…) AND source = penalara" no encuentra nada,
 * y el import inserta un juego nuevo para la persona de baja — las dos en el pool de guardias con el
 * mismo horario, sin un solo error. Devolviendo antes y volviendo a traspasar después, un reimport
 * vuelve a ser idempotente y las celdas marcadas a mano durante la baja siguen el mismo camino.
 */
final class TimetableImporter
{
    private readonly AsciiSlugger $slugger;

    public function __construct(
        private readonly UserRepository $users,
        private readonly ScheduleEntryRepository $schedule,
        private readonly TimeSlotRepository $timeSlots,
        private readonly PenalaraTimetableParser $parser,
        private readonly RoomSynchroniser $rooms,
        private readonly SubstitutionRepository $substitutions,
        private readonly SubstitutionApplier $applier,
        private readonly EntityManagerInterface $em,
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
        $resolved = $this->reconcile($byTeacher);
        $matched = $resolved->matched;
        $displaced = array_values($resolved->displaced);

        // Read before writing: what is already there is what the report compares the export against.
        $teachers = array_values($matched);
        // Quien pierde su plaza entra en el reemplazo sin celdas nuevas: así se le borran las importadas,
        // que ya son de otra persona, y no sigue en el reparto de guardias con un horario ajeno.
        $replacing = [...$teachers, ...$displaced];
        $affected = $this->substitutionsAffecting($year, $replacing);
        // Contando también a quien sustituye: mientras la baja está en vigor, las celdas de la persona
        // que el export nombra están a nombre de otra, y sin esto el preview diría "0 celdas sustituyen
        // a las 0 que ya había" justo para las personas cuyo horario más se está moviendo.
        $replaced = $this->schedule->countImportedFor($year, [
            ...$replacing,
            ...array_map(static fn (Substitution $s): User => $s->getSubstitute(), $affected),
        ]);
        $stale = $this->staleTeachers($year, $replacing, $affected);
        $reassigned = $this->describeReassignments($year, $resolved);

        [$entries, $keptManual, $dropManualIds] = $this->buildEntries(
            $year,
            $byTeacher,
            $matched,
            $this->protectedDutyCells($year, $affected),
        );
        $frame = $this->buildFrame($year, $parsed->frame);
        $newRooms = [];
        if (!$dryRun) {
            // One transaction for codes and cells: if the codes moved and the cells did not, the next run
            // would find each code already with its new person, see nobody displaced, and leave the old
            // holder in the guardia pool with a timetable that is no longer theirs.
            $newRooms = $this->em->wrapInTransaction(function () use ($year, $resolved, $replacing, $entries, $dropManualIds, $frame): array {
                // Before any flush: a code changing hands would otherwise collide with its old holder on
                // the column's unique index.
                $this->users->reassignPenalaraCodes($resolved->codesToRecord);

                // Con las sustituciones deshechas: el horario vuelve un momento a la persona de baja, que
                // es a quien el export nombra, y se retraspasa al terminar. Ver la cabecera de la clase.
                return $this->applier->withSubstitutionsSuspended($year, function () use ($year, $replacing, $entries, $dropManualIds, $frame): array {
                    $this->schedule->replaceForTeachers($year, $replacing, $entries, $dropManualIds);
                    // Only when the export actually carried a marco horario: a planificador without one
                    // must not wipe the day's shape, which is what the break duty rota reads its times from.
                    if ([] !== $frame) {
                        $this->timeSlots->replaceForYear($year, $frame);
                    }

                    // The cells are in; now make sure every room they name has a card and points at it. A
                    // dry run must not do this — previewing an import may not create anything, catalogue
                    // included.
                    return $this->rooms->sync()->createdCodes;
                });
            });
        }

        $guardias = \count(array_filter($entries, static fn (ScheduleEntry $e): bool => ScheduleActivityKind::LECTIVE !== $e->getKind()));

        return new TimetableImportResult(
            \count($entries),
            $guardias,
            \count($matched),
            $resolved->unmatched,
            $dryRun,
            $replaced,
            $keptManual,
            \count($dropManualIds),
            $stale,
            \count($frame),
            \count(array_filter($frame, static fn (TimeSlot $s): bool => $s->isBreak())),
            $parsed->frameConflicts,
            $newRooms,
            array_map(
                static fn (Substitution $s): string => sprintf(
                    '%s sustituye a %s',
                    $s->getSubstitute()->getFullName(),
                    $s->getSubstitutedTeacher()->getFullName(),
                ),
                $affected,
            ),
            $reassigned,
        );
    }

    /**
     * Las celdas de guardia que el import no puede pisar, indexadas por la persona A LA QUE EL EXPORT
     * NOMBRA.
     *
     * El remapeo es el detalle que evita un choque silencioso. Mientras dura una baja, una guardia
     * marcada a mano queda a nombre de quien sustituye, así que
     * {@see ScheduleEntryRepository::protectedDutyCells()} la devuelve bajo el id de esa persona — y el
     * import busca por el id de la persona de baja, que es a quien el export nombra. Sin remapear no la
     * encuentra: la celda no se protege y, peor, si el nuevo horario pone una clase en ese tramo tampoco
     * se marca para borrar, así que al reponer la sustitución esa persona acaba con una guardia y una
     * clase a la misma hora. Es exactamente el caso para el que existe {@code dropManualIds}.
     *
     * Los ids de fila que salen de aquí siguen siendo válidos para borrar: lo que cambia al traspasar es
     * el docente de la fila, no la fila.
     *
     * @param AcademicYear       $year     el curso destino
     * @param list<Substitution> $affected las sustituciones en vigor que este import repone
     *
     * @return array<int, array<string, int>> id de docente → "día:tramo" → id de celda
     */
    private function protectedDutyCells(AcademicYear $year, array $affected): array
    {
        $protected = $this->schedule->protectedDutyCells($year);
        foreach ($affected as $substitution) {
            $substituteId = (int) $substitution->getSubstitute()->getId();
            if (isset($protected[$substituteId])) {
                $protected[(int) $substitution->getSubstitutedTeacher()->getId()] = $protected[$substituteId];
            }
        }

        return $protected;
    }

    /**
     * Las sustituciones en vigor del curso que afectan a alguien que el export nombra — las que este
     * import va a deshacer y volver a aplicar.
     *
     * Se filtra por las personas resueltas y no se devuelven todas las del curso: una sustitución de
     * alguien que este export no trae no la toca nadie, y anunciarla en el preview sería ruido sobre una
     * pantalla cuyo valor es exactamente que solo diga lo que va a pasar.
     *
     * @param AcademicYear $year     el curso destino
     * @param list<User>   $teachers las personas cuyo horario importado reemplaza este import: las
     *                               resueltas y las que pierden su plaza
     *
     * @return list<Substitution> las sustituciones abiertas afectadas
     */
    private function substitutionsAffecting(AcademicYear $year, array $teachers): array
    {
        $matchedIds = array_flip(array_map(static fn (User $u): int => (int) $u->getId(), $teachers));

        return array_values(array_filter(
            $this->substitutions->findOpenFor($year),
            static fn (Substitution $s): bool => isset($matchedIds[(int) $s->getSubstitutedTeacher()->getId()]),
        ));
    }

    /**
     * The teachers who hold a timetable in this course that the export does not re-import. An import
     * only ever touches the teachers it resolved, so someone who left the centre mid-course keeps their
     * cells — and their guardia slots keep feeding the assignment engine. Nothing is deleted on a guess:
     * they are listed so a person decides.
     *
     * Quien está cubriendo una baja larga queda fuera de la lista: su horario no es uno que nadie
     * reimporta, es el prestado de la persona a la que sustituye, y el propio import lo repone al
     * terminar. Sin esta excepción saldría señalado en cada import mientras dure la baja, y una lista de
     * avisos que siempre trae el mismo falso positivo deja de leerse.
     *
     * Quien pierde su plaza tampoco sale: su horario importado se borra en este mismo import, y el
     * preview ya lo nombra en el aviso de las plazas que cambian de persona.
     *
     * @param AcademicYear       $year     the target course
     * @param list<User>         $teachers the teachers whose imported timetable this run replaces
     * @param list<Substitution> $affected las sustituciones en vigor que este import va a reponer
     *
     * @return list<string> their full names, alphabetically
     */
    private function staleTeachers(AcademicYear $year, array $teachers, array $affected): array
    {
        $excusedIds = array_flip([
            ...array_map(static fn (User $u): int => (int) $u->getId(), $teachers),
            ...array_map(static fn (Substitution $s): int => (int) $s->getSubstitute()->getId(), $affected),
        ]);

        return array_values(array_map(
            static fn (User $u): string => $u->getFullName(),
            array_filter(
                $this->schedule->teachersWithEntries($year),
                static fn (User $u): bool => !isset($excusedIds[$u->getId()]),
            ),
        ));
    }

    /**
     * Una línea por plaza que cambia de persona, para el preview: a quién va y cuántas celdas importadas
     * pierde quien la tenía. Es lo único de este import que borra el horario de alguien a quien el export
     * no nombra, así que tiene que decirse antes de confirmar y con nombres.
     *
     * @param AcademicYear          $year     el curso destino
     * @param TeacherReconciliation $resolved cómo se resolvieron los profesores del export
     *
     * @return list<string> las líneas, en el orden del export
     */
    private function describeReassignments(AcademicYear $year, TeacherReconciliation $resolved): array
    {
        $cells = $this->schedule->countImportedByTeacher($year, array_values($resolved->displaced));

        // With two arrays array_map already returns a list, keyed 0..n.
        return array_map(
            static function (User $holder, string|int $code) use ($cells, $resolved): string {
                $n = $cells[(int) $holder->getId()] ?? 0;

                return sprintf(
                    'La plaza de %s pasa a %s: se le %s %d %s.',
                    $holder->getFullName(),
                    $resolved->matched[$code]->getFullName(),
                    1 === $n ? 'quita' : 'quitan',
                    $n,
                    1 === $n ? 'celda importada' : 'celdas importadas',
                );
            },
            $resolved->displaced,
            array_keys($resolved->displaced),
        );
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
     * Resolves each Peñalara teacher to a {@see User}, without writing anything.
     *
     * Two passes, because a code is a post and not a person: Peñalara hands the code of someone on
     * leave to their substitute, so the same code can name somebody else from one course to the next.
     *
     * 1. A stored code whose holder's name still fits the export's ({@see namesFit()}) is a match.
     * 2. Everyone else is matched by a unique name among the people pass 1 did not take — whether or
     *    not they already hold some code. When the code they come with belonged to someone else, that
     *    holder is displaced: the export gives their post to another person.
     *
     * A code held by somebody whose name does not fit, with nobody in the app by the export's name, is
     * left unmatched and its holder untouched: the person the export means has not been registered
     * yet, and taking the timetable away from the holder on that alone would be deleting on a guess.
     *
     * @param array<string, array{name: string, dtos: list<ScheduleEntryDto>}> $byTeacher the teachers to resolve
     *
     * @return TeacherReconciliation who matched, who did not, and which codes change hands
     */
    private function reconcile(array $byTeacher): TeacherReconciliation
    {
        $byCode = [];
        $byName = [];
        foreach ($this->users->findAll() as $user) {
            if (null !== $user->getPenalaraCode()) {
                $byCode[$user->getPenalaraCode()] = $user;
            }
            $byName[$this->nameKey($user->getFullName())][] = $user;
        }

        $matched = [];
        $claimed = [];
        foreach ($byTeacher as $code => $teacher) {
            $holder = $byCode[$code] ?? null;
            if (null !== $holder && $this->namesFit($holder->getFullName(), $teacher['name'])) {
                $matched[$code] = $holder;
                $claimed[$holder->getId()] = true;
            }
        }

        $unmatched = [];
        $codesToRecord = [];
        $holders = [];
        foreach ($byTeacher as $code => $teacher) {
            if (isset($matched[$code])) {
                continue;
            }

            $candidates = array_values(array_filter(
                $byName[$this->nameKey($teacher['name'])] ?? [],
                static fn (User $u): bool => !isset($claimed[$u->getId()]),
            ));
            if (1 !== \count($candidates)) {
                $unmatched[$code] = $teacher['name'];
                continue;
            }

            $user = $candidates[0];
            // Claimed so a second same-named teacher in this run cannot grab them too.
            $claimed[$user->getId()] = true;
            $matched[$code] = $user;
            $codesToRecord[$code] = $user;
            if (isset($byCode[$code])) {
                $holders[$code] = $byCode[$code];
            }
        }

        // A holder who got another code in this same run keeps a timetable: only those left with none
        // are really displaced.
        $displaced = array_filter($holders, static fn (User $u): bool => !isset($claimed[$u->getId()]));

        return new TeacherReconciliation($matched, $unmatched, $codesToRecord, $displaced);
    }

    /**
     * Whether a stored name and the export's name can be the same person: the words of one are all in
     * the other. Looser than {@see nameKey()} on purpose — "Judit Cervantes Sánchez" in the app is
     * "Cervantes Sanchez, Maria Judit" in Peñalara — and strict enough that a substitute who inherited
     * the code ("Visiers Lecanda, M.Luisa" → "Carvajal Castellanos, Diana") never passes for the holder.
     *
     * @param string $stored   the name the app has for the code's holder
     * @param string $exported the name the export gives under that code
     *
     * @return bool true when either name's words contain the other's
     */
    private function namesFit(string $stored, string $exported): bool
    {
        $a = $this->nameTokens($stored);
        $b = $this->nameTokens($exported);

        // An empty name would be a subset of anything and keep any code: it never fits.
        if ([] === $a || [] === $b) {
            return false;
        }

        return [] === array_diff($a, $b) || [] === array_diff($b, $a);
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
        return implode(' ', $this->nameTokens($name));
    }

    /**
     * The words of a person's name, accent-stripped, lower-cased and sorted — the common ground of
     * {@see nameKey()} and {@see namesFit()}.
     *
     * @param string $name the full name
     *
     * @return list<string> the name's words
     */
    private function nameTokens(string $name): array
    {
        $ascii = $this->slugger->slug($name, ' ')->lower()->toString();
        $tokens = array_values(array_filter(explode(' ', $ascii), static fn (string $t): bool => '' !== $t));
        sort($tokens);

        return $tokens;
    }
}
