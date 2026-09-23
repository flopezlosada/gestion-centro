<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\RecurringMeetingGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates next week's meetings of the groups that repeat weekly (tutores, departamentos, CCP…). Daily and
 * idempotent: each group remembers how far it has been generated, so re-running it creates nothing new
 * and a meeting its convener deleted is never brought back ({@see RecurringMeetingGenerator}).
 *
 * Hereda de {@see AbstractCronCommand}: gate, cerrojo y registro los pone la base.
 */
#[AsCommand(name: 'app:meetings:generate-recurring', description: 'Crea las reuniones de la semana que viene de los grupos que se repiten cada semana')]
final class GenerateRecurringMeetingsCommand extends AbstractCronCommand
{
    public function __construct(private readonly RecurringMeetingGenerator $generator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Ejecuta aunque la tarea esté pausada en los ajustes');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        ['created' => $created, 'stuck' => $stuck] = $this->generator->generate(new \DateTimeImmutable('now'));
        $detail = \sprintf('%d reuniones periódicas creadas.', $created);
        if ([] !== $stuck) {
            // No es un fallo de la tarea: falta un dato (el curso o su horario). Se dice para que se vea en
            // la pantalla de crons, y el día se reintenta solo en cuanto el dato está.
            $detail .= \sprintf(' Sin curso u hora en el horario para: %s.', implode(', ', array_unique($stuck)));
        }

        (new SymfonyStyle($input, $output))->success($detail);

        return $created > 0 ? $this->didWork($detail) : $this->nothingToDo($detail);
    }
}
