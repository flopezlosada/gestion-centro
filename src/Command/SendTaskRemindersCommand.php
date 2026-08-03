<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\DailyNotificationSweep;
use App\Service\NotificationPurger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sends the daily task reminders (due in 15/7 days) and escalations (overdue) — meant to run once a
 * day from cron — y, en la misma pasada, retira los avisos caducados.
 *
 * La purga viaja aquí y no en un comando propio a propósito: es el único barrido DIARIO que existe, y
 * dar de alta una línea más de crontab en el hosting es un trámite manual con el centro que ya ha
 * costado un despliegue. El criterio de caducidad está en {@see NotificationPurger} y el barrido
 * completo en {@see DailyNotificationSweep}, compartido con la vía HTTP.
 */
#[AsCommand(name: 'app:tasks:send-reminders', description: 'Envía avisos de tareas próximas, escala las que están fuera de plazo y retira los avisos caducados')]
final class SendTaskRemindersCommand extends Command
{
    public function __construct(private readonly DailyNotificationSweep $sweep)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Fix the reference day to the centre's timezone, so "today" does not drift to UTC near
        // midnight regardless of the host's default timezone.
        $result = $this->sweep->run(new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid')));

        (new SymfonyStyle($input, $output))->success(sprintf(
            '%d avisos enviados. %d avisos caducados retirados (leídos a los %d días, sin abrir a los %d).',
            $result['sent'],
            $result['purged'],
            NotificationPurger::READ_DAYS,
            NotificationPurger::UNREAD_DAYS,
        ));

        return Command::SUCCESS;
    }
}
