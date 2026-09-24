<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\MeetingGroup;
use App\Penalara\PenalaraMeetingDto;
use App\Service\MeetingGroupImporter;
use App\Service\MeetingGroupImportResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Imports the standing weekly meetings (tutores, departamentos, CCP…) from a Peñalara planificador into
 * the meeting groups. The work lives in {@see MeetingGroupImporter}; this is the console entry point.
 *
 * Safe to re-run: it finds each group by its Peñalara name and only rewrites day, period and members.
 * When a group was changed in the application since the last import, it asks before overwriting it —
 * and in a non-interactive run (or with --dry-run) it never overwrites one.
 */
#[AsCommand(name: 'app:import-meeting-groups', description: 'Importa las reuniones semanales (tutores, departamentos, CCP…) del planificador de Peñalara')]
final class ImportMeetingGroupsCommand extends Command
{
    public function __construct(private readonly MeetingGroupImporter $importer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('planificador', InputArgument::REQUIRED, 'Ruta al planificador.xml de Peñalara')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Analiza y muestra el resumen sin escribir en la base de datos');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = (string) $input->getArgument('planificador');
        if (!is_readable($path)) {
            $io->error(sprintf('No se puede leer el planificador: %s', $path));

            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $ask = static fn (MeetingGroup $group, PenalaraMeetingDto $meeting): bool => !$dryRun && $io->confirm(
            sprintf('«%s» se cambió en la aplicación (día, hora o personas) y Peñalara dice otra cosa. ¿Lo sobrescribo con lo de Peñalara?', $group->getName()),
            false,
        );

        try {
            $result = $this->importer->import((string) file_get_contents($path), $dryRun, $ask);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->report($io, $result);

        return Command::SUCCESS;
    }

    /**
     * Prints what was done and, apart, what needs somebody: the members nobody could be matched to, the
     * groups left as edited here, and the weekly groups that still have nobody to convene them.
     *
     * @param SymfonyStyle             $io     the console style
     * @param MeetingGroupImportResult $result the import outcome
     */
    private function report(SymfonyStyle $io, MeetingGroupImportResult $result): void
    {
        $io->success(sprintf(
            'Reuniones semanales: %d nuevas, %d actualizadas, %d sin cambios.%s',
            \count($result->created),
            \count($result->updated),
            \count($result->unchanged),
            $result->dryRun ? ' [dry-run: nada escrito]' : '',
        ));

        if ([] !== $result->keptEdited) {
            $io->note(sprintf(
                $result->dryRun
                    ? 'Cambiadas en la aplicación desde el último import (al importar de verdad se preguntará por cada una): %s.'
                    : 'Se han dejado como estaban en la aplicación: %s.',
                implode(', ', $result->keptEdited),
            ));
        }

        if ([] !== $result->unmatched) {
            $io->warning(sprintf('%d persona(s) de Peñalara sin usuario enlazado en la aplicación: no se añaden a sus reuniones. Importa antes el horario, que es el que las enlaza:', \count($result->unmatched)));
            $io->listing(array_map(
                static fn (string $person, array $meetings): string => sprintf('%s — %s', $person, implode(', ', $meetings)),
                array_keys($result->unmatched),
                $result->unmatched,
            ));
        }

        if ([] !== $result->skipped) {
            $io->warning(sprintf('No son semanales de un solo tramo y no se importan: %s.', implode(', ', $result->skipped)));
        }

        if ([] !== $result->missing) {
            $io->warning(sprintf('Ya no están en este planificador (no se tocan; desactívalas en /admin si ya no se celebran): %s.', implode(', ', $result->missing)));
        }

        if ([] !== $result->defaulted) {
            $io->note($result->dryRun
                ? 'Se les pondría quien convoca por defecto (se cambia en /admin/grupos-de-reunion):'
                : 'Quien convoca por defecto (se cambia en /admin/grupos-de-reunion):');
            $io->listing(array_map(
                static fn (string $group, string $person): string => sprintf('%s — %s', $group, $person),
                array_keys($result->defaulted),
                $result->defaulted,
            ));
        }

        if ([] !== $result->noConvener) {
            $io->note(sprintf('Sin nadie que convoque, así que todavía no se generan sus reuniones. Asígnalo en /admin/grupos-de-reunion: %s.', implode(', ', $result->noConvener)));
        }
    }
}
