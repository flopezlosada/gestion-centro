<?php

declare(strict_types=1);

namespace App\Command;

use App\Guardia\PastDocumentPurger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes the task documents of past guardias and the documents of past copy orders
 * ({@see PastDocumentPurger}).
 */
#[AsCommand(name: 'app:guardias:purge-past-documents', description: 'Borra los documentos de tarea y de fotocopias de días ya pasados')]
final class PurgePastDocumentsCommand extends AbstractCronCommand
{
    public function __construct(private readonly PastDocumentPurger $purger)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Ejecuta aunque la tarea esté pausada en los ajustes');
    }

    /**
     * Purges what is past and reports how many documents of each kind went.
     */
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $purged = $this->purger->purge(new \DateTimeImmutable());
        $detail = \sprintf('%d documentos de guardia y %d de fotocopias borrados.', $purged['covers'], $purged['orders']);

        $io = new SymfonyStyle($input, $output);

        // La fila ya está marcada: ninguna pasada posterior volverá a por estos ficheros, así que hay que verlo.
        if ([] !== $purged['unremoved']) {
            $io->error($detail.' No se pudieron borrar del disco: '.implode(', ', $purged['unremoved']));

            return Command::FAILURE;
        }
        $io->writeln($detail);

        return 0 === $purged['covers'] + $purged['orders'] ? $this->nothingToDo($detail) : $this->didWork($detail);
    }
}
