<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\GuardiaDutyReminder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manda el doble recordatorio de guardia —la tarde anterior y esa misma mañana— a quien la tiene
 * asignada. Uno de los cuatro barridos de cadencia de minutos: fuera de las dos ventanas no hace nada,
 * así que ejecutarlo más veces solo hace que el aviso llegue antes dentro de su ventana.
 *
 * Idempotente por guardia y disparo ({@see GuardiaDutyReminder}), así que una pasada repetida no
 * duplica. Hereda de {@see AbstractCronCommand}: gate, cerrojo y registro los pone la base.
 */
#[AsCommand(name: 'app:guardias:send-duty-reminders', description: 'Recuerda sus guardias al profesorado: la tarde anterior y esa misma mañana')]
final class SendGuardiaDutyRemindersCommand extends AbstractCronCommand
{
    public function __construct(private readonly GuardiaDutyReminder $reminder)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Ejecuta aunque la tarea esté pausada en los ajustes');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        // Zona por defecto de PHP a propósito: el barrido compara horas de reloj contra las del marco
        // horario importado (ver el doc de GuardiaDutyReminder).
        $count = $this->reminder->sendDue(new \DateTimeImmutable('now'));
        $detail = \sprintf('%d recordatorios de guardia enviados.', $count);

        (new SymfonyStyle($input, $output))->success($detail);

        return $count > 0 ? $this->didWork($detail) : $this->nothingToDo('Fuera de las dos ventanas de aviso, o nadie con guardia.');
    }
}
