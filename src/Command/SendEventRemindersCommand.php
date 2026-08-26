<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\EventReminderNotifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pushes the personal agenda reminders that have come due — one of the four minute-level sweeps, so the
 * clock has to call every few minutes. The sweep is idempotent, so running it more often only makes the
 * reminders land closer to the antelación the owner asked for; running it less makes them land late.
 *
 * Hereda de {@see AbstractCronCommand}: el gate, el cerrojo y el registro los pone la base.
 */
#[AsCommand(name: 'app:events:send-reminders', description: 'Envía los avisos de eventos de la agenda personal que tocan ahora')]
final class SendEventRemindersCommand extends AbstractCronCommand
{
    public function __construct(private readonly EventReminderNotifier $notifier)
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
        // only "now" that compares correctly (see EventReminderNotifier's class doc).
        $count = $this->notifier->sendDue(new \DateTimeImmutable('now'));
        $detail = \sprintf('%d avisos de agenda enviados.', $count);

        (new SymfonyStyle($input, $output))->success($detail);

        // Con una pasada cada cinco minutos, la inmensa mayoría no tiene nada que mandar. Declararlo
        // es lo que permite que el registro distinga «funciona y no tocaba» de «hizo trabajo»; sin eso
        // ninguna caída se vería, porque «done» sería la respuesta a todo.
        return $count > 0 ? $this->didWork($detail) : $this->nothingToDo('Ningún evento a punto de empezar.');
    }
}
