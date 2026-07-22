<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AcademicYear;
use App\Guardia\TimetableImporter;
use App\Guardia\TimetableImportResult;
use App\Repository\AcademicYearRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Imports the weekly timetable (teaching sessions and guardia/collaborator duties) from a pair of
 * Peñalara GHC exports — the planificador (name dictionary) and the resolved "Horario.xml" — into a
 * given course's {@see \App\Entity\ScheduleEntry} rows.
 *
 * The parsing, teacher reconciliation and persistence live in {@see TimetableImporter}, shared with
 * the admin self-service screen; this command is just the console entry point (resolve the course,
 * read the files, print the summary).
 */
#[AsCommand(name: 'app:import-timetable', description: 'Importa el horario semanal (lectivas y guardias) de un curso desde los ficheros de Peñalara GHC')]
final class ImportTimetableCommand extends Command
{
    public function __construct(
        private readonly AcademicYearRepository $years,
        private readonly TimetableImporter $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('curso', InputArgument::REQUIRED, 'Curso escolar destino en formato "YYYY-YYYY" (p. ej. "2026-2027"); su estructura debe existir en /admin/trimestres')
            ->addArgument('planificador', InputArgument::REQUIRED, 'Ruta al planificador.xml de Peñalara (diccionario de nombres)')
            ->addArgument('horario', InputArgument::REQUIRED, 'Ruta al Horario.xml resuelto de Peñalara')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Analiza y muestra el resumen sin escribir en la base de datos');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $schoolYear = (string) $input->getArgument('curso');
        $year = $this->years->findBySchoolYear($schoolYear);
        if (!$year instanceof AcademicYear) {
            $io->error(sprintf('No existe la estructura del curso "%s". Créala en /admin/trimestres antes de importar su horario.', $schoolYear));

            return Command::FAILURE;
        }

        $planificador = $this->read((string) $input->getArgument('planificador'), 'planificador', $io);
        $horario = $this->read((string) $input->getArgument('horario'), 'horario', $io);
        if (null === $planificador || null === $horario) {
            return Command::FAILURE;
        }

        try {
            $result = $this->importer->import($year, $planificador, $horario, (bool) $input->getOption('dry-run'));
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->report($io, $year, $result);

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
     * Prints the import summary: totals, guardia count and — the part that needs action — the
     * teachers nobody could be matched to.
     *
     * @param SymfonyStyle          $io     the console style
     * @param AcademicYear          $year   the target course
     * @param TimetableImportResult $result the import outcome
     */
    private function report(SymfonyStyle $io, AcademicYear $year, TimetableImportResult $result): void
    {
        $io->success(sprintf(
            '%s: %d celdas de horario (%d de guardia/colaboración) para %d profesores emparejados.%s',
            $year->getSchoolYear(),
            $result->entryCount,
            $result->guardiaCount,
            $result->matchedCount,
            $result->dryRun ? ' [dry-run: nada escrito]' : '',
        ));

        if ([] !== $result->unmatched) {
            $io->warning(sprintf('%d profesores del horario sin emparejar con un usuario (su horario NO se ha importado):', \count($result->unmatched)));
            $io->listing(array_map(
                static fn (string $code, string $name): string => sprintf('%s (código %s)', $name, $code),
                array_keys($result->unmatched),
                array_values($result->unmatched),
            ));
            $io->note('Da de alta a esas personas (o corrige su nombre) y vuelve a importar: se emparejarán por nombre y quedará guardado su código.');
        }
    }
}
