<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\TaskReminderNotifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sends the daily task reminders (due in 15/7 days) and escalations (overdue) — meant to run once a
 * day from cron.
 */
#[AsCommand(name: 'app:tasks:send-reminders', description: 'Envía avisos de tareas próximas y escala las vencidas')]
final class SendTaskRemindersCommand extends Command
{
    public function __construct(private readonly TaskReminderNotifier $notifier)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->notifier->sendDue(new \DateTimeImmutable());
        (new SymfonyStyle($input, $output))->success(sprintf('%d avisos enviados.', $count));

        return Command::SUCCESS;
    }
}
