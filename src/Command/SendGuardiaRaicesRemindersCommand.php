<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\GuardiaRaicesReminder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pushes the "apunta las ausencias en RAICES" reminder to whoever is covering a guardia right now —
 * meant to run from cron every five minutes, alongside {@see SendEventRemindersCommand}. The sweep is
 * idempotent (each cover is stamped once), so running it more often only makes the reminder land closer
 * to the start of the period; running it less makes it land later into the class.
 */
#[AsCommand(name: 'app:guardias:send-raices-reminders', description: 'Avisa de apuntar las ausencias en RAICES a quien está haciendo una guardia ahora')]
final class SendGuardiaRaicesRemindersCommand extends Command
{
    public function __construct(private readonly GuardiaRaicesReminder $reminder)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // PHP's default time zone on purpose: the sweep compares clock times against the period times of
        // the imported timetable (see GuardiaRaicesReminder's class doc).
        $count = $this->reminder->sendDue(new \DateTimeImmutable('now'));
        (new SymfonyStyle($input, $output))->success(\sprintf('%d avisos de RAICES enviados.', $count));

        return Command::SUCCESS;
    }
}
