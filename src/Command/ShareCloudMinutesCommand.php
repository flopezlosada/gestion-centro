<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Cloud\CloudShareQueue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:cloud:share-minutes', description: 'Comparte en la nube de EducaMadrid las actas que quedaron en cola porque la nube solo deja unas pocas cada diez minutos')]
final class ShareCloudMinutesCommand extends AbstractCronCommand
{
    public function __construct(private readonly CloudShareQueue $queue)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Ejecuta aunque la tarea esté pausada en los ajustes');
    }

    /**
     * Makes the pending shares the cloud allows now. Stopping at the cloud's limit is how a long queue
     * waits, so it is a normal run; a cloud that does not answer or is not connected is a failure, so it
     * shows in the cron's record.
     */
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $run = $this->queue->process();
        $detail = \sprintf('%d comparticiones hechas, %d imposibles.', $run->shared, $run->failed)
            .(null === $run->stoppedBy ? '' : ' Se para: '.$run->stoppedBy);

        if (null !== $run->stoppedBy && !$run->throttled) {
            $io->error($detail);

            return Command::FAILURE;
        }
        $io->writeln($detail);

        return 0 === $run->shared + $run->failed ? $this->nothingToDo($detail) : $this->didWork($detail);
    }
}
