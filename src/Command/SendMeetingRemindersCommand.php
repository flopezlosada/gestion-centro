<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\MeetingReminderNotifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pushes the reminder of the meetings about to start to everybody expected at them — one of the four
 * minute-level sweeps. On the command line each sweep is run on its own to see what it does; the clock
 * fires all of them from a single tick, so adding one never costs a line in anybody's cron table.
 *
 * The sweep is idempotent (each meeting is stamped once), so running it more often only makes the
 * reminder land closer to the antelación that was asked for; running it less makes it land late.
 *
 * Hereda de {@see AbstractCronCommand}: gate, cerrojo y registro los pone la base.
 */
#[AsCommand(name: 'app:meetings:send-reminders', description: 'Avisa a las personas convocadas de las reuniones que están a punto de empezar')]
final class SendMeetingRemindersCommand extends AbstractCronCommand
{
    public function __construct(private readonly MeetingReminderNotifier $notifier)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Ejecuta aunque la tarea esté pausada en los ajustes');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        // PHP's default time zone on purpose: it is the one Doctrine wrote remind_at in, so this is the
        // only "now" that compares correctly (see MeetingReminderNotifier's class doc).
        $count = $this->notifier->sendDue(new \DateTimeImmutable('now'));
        $detail = \sprintf('%d avisos de reuniones enviados.', $count);

        (new SymfonyStyle($input, $output))->success($detail);

        return $count > 0 ? $this->didWork($detail) : $this->nothingToDo('Ninguna reunión a punto de empezar.');
    }
}
