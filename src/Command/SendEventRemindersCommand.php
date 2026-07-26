<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\EventReminderNotifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pushes the personal agenda reminders that have come due — meant to run from cron every five minutes.
 * The sweep is idempotent, so running it more often only makes the reminders land closer to the
 * antelación the owner asked for; running it less makes them land late.
 */
#[AsCommand(name: 'app:events:send-reminders', description: 'Envía los avisos de eventos de la agenda personal que tocan ahora')]
final class SendEventRemindersCommand extends Command
{
    public function __construct(private readonly EventReminderNotifier $notifier)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // PHP's default time zone on purpose: it is the one Doctrine wrote remind_at in, so this is the
        // only "now" that compares correctly (see EventReminderNotifier's class doc).
        $count = $this->notifier->sendDue(new \DateTimeImmutable('now'));
        (new SymfonyStyle($input, $output))->success(\sprintf('%d avisos de agenda enviados.', $count));

        return Command::SUCCESS;
    }
}
