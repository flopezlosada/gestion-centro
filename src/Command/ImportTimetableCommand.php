<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ScheduleEntry;
use App\Entity\User;
use App\Enum\ScheduleActivityKind;
use App\Enum\Weekday;
use App\Penalara\PenalaraTimetableParser;
use App\Penalara\ScheduleEntryDto;
use App\Repository\ScheduleEntryRepository;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Imports the weekly timetable (teaching sessions and guardia/collaborator duties) from a pair of
 * Peñalara GHC exports — the planificador (name dictionary) and the resolved "Horario.xml" — into
 * {@see ScheduleEntry} rows.
 *
 * Reconciliation is the crux: the export identifies teachers by a numeric Peñalara code, not by the
 * e-mail our people are keyed on. A teacher is matched by their stored {@see User::$penalaraCode}
 * first and, failing that, by name (accent-insensitive, order-insensitive token set — the export's
 * "Apellidos, Nombre" still matches our "Nombre Apellidos"); a unique name match records the code so
 * every later import re-links without re-matching. Teachers that match nobody are reported and their
 * schedule is skipped until someone reconciles them — never guessed. Idempotent: each run replaces
 * only the reconciled teachers' entries.
 */
#[AsCommand(name: 'app:import-timetable', description: 'Importa el horario semanal (lectivas y guardias) desde los ficheros de Peñalara GHC')]
final class ImportTimetableCommand extends Command
{
    private readonly AsciiSlugger $slugger;

    public function __construct(
        private readonly UserRepository $users,
        private readonly ScheduleEntryRepository $schedule,
        private readonly PenalaraTimetableParser $parser,
    ) {
        parent::__construct();
        $this->slugger = new AsciiSlugger();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('planificador', InputArgument::REQUIRED, 'Ruta al planificador.xml de Peñalara (diccionario de nombres)')
            ->addArgument('horario', InputArgument::REQUIRED, 'Ruta al Horario.xml resuelto de Peñalara')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Analiza y muestra el resumen sin escribir en la base de datos');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $planificador = $this->read((string) $input->getArgument('planificador'), 'planificador', $io);
        $horario = $this->read((string) $input->getArgument('horario'), 'horario', $io);
        if (null === $planificador || null === $horario) {
            return Command::FAILURE;
        }

        try {
            $dtos = $this->parser->parse($planificador, $horario);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $byTeacher = $this->groupByTeacher($dtos);
        [$matched, $unmatched] = $this->reconcile($byTeacher);

        $entries = $this->buildEntries($byTeacher, $matched);
        if (!$dryRun) {
            $this->schedule->replaceForTeachers(array_values($matched), $entries);
        }

        $this->report($io, $entries, $matched, $unmatched, $dryRun);

        return Command::SUCCESS;
    }

    /**
     * Reads a file, reporting a clear error and returning null when it is unreadable.
     *
     * @param string       $path  the file path
     * @param string       $label short document name for the error message
     * @param SymfonyStyle $io    the console style
     *
     * @return string|null the contents, or null on error
     */
    private function read(string $path, string $label, SymfonyStyle $io): ?string
    {
        if (!is_readable($path)) {
            $io->error(sprintf('No se puede leer el %s: %s', $label, $path));

            return null;
        }

        return (string) file_get_contents($path);
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
     * Builds the {@see ScheduleEntry} rows for the reconciled teachers.
     *
     * @param array<string, array{name: string, dtos: list<ScheduleEntryDto>}> $byTeacher the parsed teachers
     * @param array<string, User>                                              $matched   resolved code → user
     *
     * @return list<ScheduleEntry> the entries to persist
     */
    private function buildEntries(array $byTeacher, array $matched): array
    {
        $entries = [];
        foreach ($matched as $code => $user) {
            foreach ($byTeacher[$code]['dtos'] as $dto) {
                $entries[] = (new ScheduleEntry())
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
     * Prints the import summary: totals, guardia count and — the part that needs action — the
     * teachers nobody could be matched to.
     *
     * @param SymfonyStyle        $io        the console style
     * @param list<ScheduleEntry> $entries   the entries built
     * @param array<string, User> $matched   resolved code → user
     * @param array<string, string> $unmatched unresolved code → name
     * @param bool                $dryRun    whether nothing was written
     */
    private function report(SymfonyStyle $io, array $entries, array $matched, array $unmatched, bool $dryRun): void
    {
        $guardias = \count(array_filter($entries, static fn (ScheduleEntry $e): bool => ScheduleActivityKind::LECTIVE !== $e->getKind()));

        $io->success(sprintf(
            '%d celdas de horario (%d de guardia/colaboración) para %d profesores emparejados.%s',
            \count($entries),
            $guardias,
            \count($matched),
            $dryRun ? ' [dry-run: nada escrito]' : '',
        ));

        if ([] !== $unmatched) {
            $io->warning(sprintf('%d profesores del horario sin emparejar con un usuario (su horario NO se ha importado):', \count($unmatched)));
            $io->listing(array_map(
                static fn (string $code, string $name): string => sprintf('%s (código %s)', $name, $code),
                array_keys($unmatched),
                array_values($unmatched),
            ));
            $io->note('Da de alta a esas personas (o corrige su nombre) y vuelve a importar: se emparejarán por nombre y quedará guardado su código.');
        }
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
