<?php

declare(strict_types=1);

namespace App\Service;

/**
 * El barrido DIARIO de avisos, completo: envía lo que toca hoy y retira lo que ya caducó.
 *
 * Existe porque ese barrido se dispara desde DOS sitios —{@see \App\Command\SendTaskRemindersCommand}
 * por CLI y {@see \App\Controller\CronController::taskReminders()} por HTTP, según lo que el hosting
 * permita— y los dos tienen que hacer exactamente lo mismo. Con la lógica duplicada, añadir un paso
 * (justo lo que es la purga) lo deja fuera de una de las dos vías y el síntoma es que "en producción no
 * caduca nada", sin ningún error. Un solo sitio que cambiar.
 */
final class DailyNotificationSweep
{
    public function __construct(
        private readonly TaskReminderNotifier $notifier,
        private readonly NotificationPurger $purger,
    ) {
    }

    /**
     * Corre el barrido del día.
     *
     * Primero avisar y después limpiar, no al revés: así un aviso recién creado no puede cruzarse con
     * su propio corte de caducidad.
     *
     * @param \DateTimeImmutable $now el instante de referencia, en la zona horaria del centro
     *
     * @return array{sent: int, purged: int} avisos enviados y avisos caducados retirados
     */
    public function run(\DateTimeImmutable $now): array
    {
        return [
            'sent' => $this->notifier->sendDue($now),
            'purged' => $this->purger->purge($now),
        ];
    }
}
