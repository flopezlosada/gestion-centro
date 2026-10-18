<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\FamilySignupRepository;
use App\Service\AppSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes the families' signups to sessions held longer ago than the centre keeps them
 * ({@see AppSettings::getFamilySignupRetentionDays()}).
 *
 * A past signup says where a named person was on a given day, and once the session is over nobody needs
 * it to organise anything. How long it is kept is the management team's call, so the plazo is a setting
 * and not a constant here; while nobody has set it, nothing is deleted. That empty default is also what
 * makes this task safe on its first tick after a deploy, which runs at once and not at its hour.
 */
#[AsCommand(name: 'app:families:purge-past-signups', description: 'Borra las inscripciones de familias a sesiones pasadas, cumplido el plazo de los datos del centro')]
final class PurgePastFamilySignupsCommand extends AbstractCronCommand
{
    public function __construct(
        private readonly AppSettings $settings,
        private readonly FamilySignupRepository $signups,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Ejecuta aunque la tarea esté pausada en los ajustes');
    }

    /**
     * Deletes what is past the plazo, or nothing while there is none.
     */
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = $this->settings->getFamilySignupRetentionDays();
        if (null === $days) {
            $detail = 'Sin plazo en los datos del centro: no se borra ninguna inscripción.';
            $io->writeln($detail);

            return $this->nothingToDo($detail);
        }

        $keptFrom = new \DateTimeImmutable(\sprintf('today -%d days', $days));
        $deleted = $this->signups->deleteHeldBefore($keptFrom);
        $detail = \sprintf('%d inscripciones de familias borradas (sesiones anteriores al %s).', $deleted, $keptFrom->format('d/m/Y'));
        $io->writeln($detail);

        return $deleted > 0 ? $this->didWork($detail) : $this->nothingToDo($detail);
    }
}
