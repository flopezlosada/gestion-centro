<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\DailyNotificationSweep;
use App\Service\NotificationPurger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sends the daily task reminders (due in 15/7 days) and escalations (overdue) — meant to run once a
 * day from cron — y, en la misma pasada, retira los avisos caducados.
 *
 * La purga viaja aquí y no en un comando propio a propósito: es el único barrido DIARIO de avisos que
 * existe, y el criterio de caducidad está en {@see NotificationPurger}. El barrido completo vive en
 * {@see DailyNotificationSweep}, compartido con la vía HTTP.
 *
 * Hereda de {@see AbstractCronCommand}, así que el gate, el cerrojo y el registro de la ejecución los
 * pone la base; aquí solo está el trabajo y la DECLARACIÓN de qué pasó.
 */
#[AsCommand(name: 'app:tasks:send-reminders', description: 'Envía avisos de tareas próximas, escala las que están fuera de plazo y retira los avisos caducados')]
final class SendTaskRemindersCommand extends AbstractCronCommand
{
    public function __construct(private readonly DailyNotificationSweep $sweep)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Ejecuta aunque la tarea esté pausada en los ajustes (nunca salta los interruptores de entrega)');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        // «Ahora» a secas, sin repetir la zona: el arranque ya ancla la del centro como zona por defecto
        // de PHP ({@see \App\Kernel}), y {@see \App\Util\AppTime} es explícito en que esparcir un
        // 'Europe/Madrid' a mano es justo lo que hay que dejar de hacer — un valor anclado en Madrid
        // comparado contra una columna hidratada en otra zona sale desplazado por el offset.
        $result = $this->sweep->run(new \DateTimeImmutable('now'));

        $detail = \sprintf(
            '%d avisos enviados. %d avisos caducados retirados (leídos a los %d días, sin abrir a los %d).',
            $result['sent'],
            $result['purged'],
            NotificationPurger::READ_DAYS,
            NotificationPurger::UNREAD_DAYS,
        );

        (new SymfonyStyle($input, $output))->success($detail);

        // Enviar y purgar son las dos formas de «hizo trabajo». Un día sin plazos que avisar y sin
        // nada caducado es el resultado sano de la mayoría de los días, y tiene que registrarse
        // distinto: si todo saliera como «done», el registro dejaría de poder distinguir una tarea que
        // trabaja de una que solo se ejecuta.
        return $result['sent'] + $result['purged'] > 0
            ? $this->didWork($detail)
            : $this->nothingToDo('Ningún plazo que avisar y ningún aviso caducado.');
    }
}
