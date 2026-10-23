<?php

declare(strict_types=1);

namespace App\Command;

use App\Guardia\AbsencePurger;
use App\Service\AppSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Forgets the teachers' absences older than the plazo of the centre's settings
 * ({@see AppSettings::getAbsenceRetention()}, {@see AbsencePurger}).
 *
 * Unlike the families' signups there is always a plazo, a week by default, so the first tick after a
 * deploy — which runs at once and not at its hour — archives everything older at once. Take a backup
 * first, or deploy with the task paused.
 */
#[AsCommand(name: 'app:guardias:purge-past-absences', description: 'Borra las ausencias del profesorado pasadas del plazo de los datos del centro y archiva sus guardias')]
final class PurgePastAbsencesCommand extends AbstractCronCommand
{
    public function __construct(
        private readonly AppSettings $settings,
        private readonly AbsencePurger $purger,
    ) {
        parent::__construct();
    }

    /**
     * The --force option, to run it while the task is paused.
     */
    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Ejecuta aunque la tarea esté pausada en los ajustes');
    }

    /**
     * Forgets what is past the plazo and reports how much went.
     */
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $retention = $this->settings->getAbsenceRetention();
        $today = new \DateTimeImmutable('today');
        $purged = $this->purger->purge($retention, $today);
        $detail = \sprintf(
            '%d ausencias borradas y %d guardias archivadas (anteriores al %s, plazo de %s).',
            $purged['absences'],
            $purged['covers'],
            $retention->keptFrom($today)->format('d/m/Y'),
            $retention->label(),
        );

        $io = new SymfonyStyle($input, $output);

        // Las filas ya no existen: ninguna pasada posterior volverá a por estos ficheros, así que hay que verlo.
        if ([] !== $purged['unremoved']) {
            $io->error($detail.' No se pudieron borrar del disco: '.implode(', ', $purged['unremoved']));

            return Command::FAILURE;
        }
        $io->writeln($detail);

        return 0 === $purged['absences'] + $purged['covers'] ? $this->nothingToDo($detail) : $this->didWork($detail);
    }
}
