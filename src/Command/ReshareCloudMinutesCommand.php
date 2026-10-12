<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\MeetingRepository;
use App\Service\Cloud\CloudAccount;
use App\Service\Cloud\CloudMinutes;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fills in the shares missing from the actas already in the cloud: those the cloud refused because it
 * allows only so many at a time — before there was a queue — and anybody convened after the acta was
 * published. Whoever already has their copy is skipped, and nobody is told anything by the app.
 *
 * Safe to run again: it only ever queues what is missing, and the scheduled task makes what does not fit
 * now.
 */
#[AsCommand(name: 'app:minutes:reshare-cloud', description: 'Vuelve a compartir en la nube las actas que ya están allí, solo con quien aún no las tiene')]
final class ReshareCloudMinutesCommand extends Command
{
    public function __construct(
        private readonly MeetingRepository $meetings,
        private readonly CloudAccount $account,
        private readonly CloudMinutes $cloudMinutes,
    ) {
        parent::__construct();
    }

    /**
     * Queues the shares of every cloud acta and says, per meeting, what is left.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->account->isConnected()) {
            $io->error('La nube de EducaMadrid no está conectada (Administración → Nube de EducaMadrid).');

            return Command::FAILURE;
        }

        $pending = 0;
        foreach ($this->meetings->findWithCloudMinutes() as $meeting) {
            $sharing = $this->cloudMinutes->reshare($meeting);
            $pending += $sharing['pending'];
            $io->writeln(\sprintf('Reunión %d: %s.', $meeting->getId(), 0 === $sharing['pending'] ? 'al día' : \sprintf('%d en cola', $sharing['pending'])));
            foreach ($sharing['problems'] as $problem) {
                $io->writeln('   · sin compartir con '.$problem);
            }
        }

        $io->success(0 === $pending ? 'Todas las actas de la nube están compartidas con quien toca.' : \sprintf('%d comparticiones en cola: las hará la tarea programada en los próximos minutos.', $pending));

        return Command::SUCCESS;
    }
}
