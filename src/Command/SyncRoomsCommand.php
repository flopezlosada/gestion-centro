<?php

declare(strict_types=1);

namespace App\Command;

use App\Space\RoomSynchroniser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Brings the space catalogue in line with the imported timetable: a card for every room the timetable
 * names, and a link from every timetable cell to its card.
 *
 * The import already does this on its way out, so this command is for the two cases it cannot cover: a
 * database whose timetable was loaded before the space module existed (staging), and recovering the
 * links after somebody edits a code by hand. Safe to run any number of times — it only ever adds.
 */
#[AsCommand(name: 'app:sync-rooms', description: 'Crea la ficha de los espacios que nombra el horario y enlaza las clases con ellos')]
final class SyncRoomsCommand extends Command
{
    public function __construct(private readonly RoomSynchroniser $synchroniser)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = $this->synchroniser->sync();

        if ($result->isEmpty()) {
            $io->success('El catálogo ya estaba al día: el horario no nombra ningún espacio que falte.');

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            '%d espacio(s) creado(s) y %d celda(s) de horario enlazada(s).',
            \count($result->createdCodes),
            $result->linkedCells,
        ));
        if ([] !== $result->createdCodes) {
            $io->listing($result->createdCodes);
            $io->note('Las fichas nuevas están sin completar: les falta el tipo y la capacidad, que el horario no trae. Complétalas en /espacios/catalogo.');
        }

        $pending = $this->synchroniser->unlinkedCells();
        if ($pending > 0) {
            $io->warning(sprintf('Quedan %d celda(s) de horario sin espacio catalogado.', $pending));
        }

        return Command::SUCCESS;
    }
}
