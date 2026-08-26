<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\GuardiaRaicesReminder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pushes the "apunta las ausencias en RAICES" reminder to whoever is covering a guardia right now — one
 * of the four minute-level sweeps. The sweep is idempotent (each cover is stamped once), so running it
 * more often only makes the reminder land closer to the start of the period; running it less makes it
 * land later into the class.
 *
 * Hereda de {@see AbstractCronCommand}: gate, cerrojo y registro los pone la base.
 */
#[AsCommand(name: 'app:guardias:send-raices-reminders', description: 'Avisa de apuntar las ausencias en RAICES a quien está haciendo una guardia ahora')]
final class SendGuardiaRaicesRemindersCommand extends AbstractCronCommand
{
    public function __construct(private readonly GuardiaRaicesReminder $reminder)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Ejecuta aunque la tarea esté pausada en los ajustes');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        // PHP's default time zone on purpose: the sweep compares clock times against the period times of
        // the imported timetable (see GuardiaRaicesReminder's class doc).
        $count = $this->reminder->sendDue(new \DateTimeImmutable('now'));
        $detail = \sprintf('%d avisos de RAICES enviados.', $count);

        (new SymfonyStyle($input, $output))->success($detail);

        return $count > 0 ? $this->didWork($detail) : $this->nothingToDo('Nadie cubriendo una guardia en este momento.');
    }
}
