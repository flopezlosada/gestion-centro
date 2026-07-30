<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\MeetingReminderNotifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pushes the reminder of the meetings about to start to everybody expected at them — meant to run from
 * cron every five minutes, alongside {@see SendEventRemindersCommand} and
 * {@see SendGuardiaRaicesRemindersCommand}. One command per sweep, like those two: on the command line each
 * one is run on its own to see what it does, while the HTTP entry point
 * ({@see \App\Controller\CronController::eventReminders()}) fires all three from a single schedule so the
 * host's cron table never has to grow.
 *
 * The sweep is idempotent (each meeting is stamped once), so running it more often only makes the reminder
 * land closer to the antelación that was asked for; running it less makes it land late.
 */
#[AsCommand(name: 'app:meetings:send-reminders', description: 'Avisa a las personas convocadas de las reuniones que están a punto de empezar')]
final class SendMeetingRemindersCommand extends Command
{
    public function __construct(private readonly MeetingReminderNotifier $notifier)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // PHP's default time zone on purpose: it is the one Doctrine wrote remind_at in, so this is the
        // only "now" that compares correctly (see MeetingReminderNotifier's class doc).
        $count = $this->notifier->sendDue(new \DateTimeImmutable('now'));
        (new SymfonyStyle($input, $output))->success(\sprintf('%d avisos de reuniones enviados.', $count));

        return Command::SUCCESS;
    }
}
