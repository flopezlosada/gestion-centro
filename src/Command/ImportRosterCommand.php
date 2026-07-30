<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\RosterImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Imports the teaching staff roster (people, departments and their cargo) from a normalised CSV
 * ({@code full_name,email,department,cargo}, produced by import/normalize_roster.py).
 *
 * The reading, matching and persistence live in {@see RosterImporter}, shared with the admin
 * self-service screen; this command is just the console entry point. The roster is personal data and
 * lives OUTSIDE the repository (a gitignored CSV).
 */
#[AsCommand(name: 'app:import-roster', description: 'Importa el claustro (personas, departamentos y cargo) desde un CSV normalizado')]
final class ImportRosterCommand extends Command
{
    public function __construct(private readonly RosterImporter $importer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('csv', InputArgument::REQUIRED, 'Ruta al CSV normalizado (full_name,email,department,cargo)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Analiza y muestra el resumen sin escribir en la base de datos');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = (string) $input->getArgument('csv');

        if (!is_readable($path)) {
            $io->error(sprintf('No se puede leer el CSV: %s', $path));

            return Command::FAILURE;
        }

        try {
            $result = $this->importer->import((string) file_get_contents($path), (bool) $input->getOption('dry-run'));
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ([] !== $result->skipped) {
            $io->warning(sprintf('%d línea(s) no se han podido leer:', \count($result->skipped)));
            $io->listing($result->skipped);
        }

        $io->success(sprintf(
            '%d docentes (%d nuevos, %d actualizados), %d departamentos.%s',
            $result->rowCount,
            \count($result->created),
            $result->updated,
            \count($result->departments),
            $result->dryRun ? ' [dry-run: nada escrito]' : '',
        ));
        $io->note('Los jefes de departamento no vienen en el origen: las unidades quedan sin responsable. Solo "Dirección" recibe acceso al back-office; el resto son marcadores.');

        return Command::SUCCESS;
    }
}
