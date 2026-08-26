<?php

declare(strict_types=1);

namespace App\Service;

/**
 * El barrido DIARIO de avisos, completo: envía lo que toca hoy y retira lo que ya caducó.
 *
 * Nació porque el barrido se disparaba desde DOS sitios que tenían que hacer lo mismo —el comando por
 * CLI y el endpoint HTTP, según lo que el hosting permitiera—, y con la lógica duplicada añadir un paso
 * (justo lo que es la purga) lo dejaba fuera de una de las dos vías, con el síntoma "en producción no
 * caduca nada" y sin ningún error.
 *
 * Desde el planificador ya solo tiene un consumidor ({@see \App\Command\SendTaskRemindersCommand}):
 * las tres vías —consola, tick y el endpoint HTTP antiguo— pasan todas por ese comando. Se queda como
 * clase porque sigue siendo lo que nombra el barrido diario Y lo que garantiza el ORDEN de sus dos
 * pasos, que no es intercambiable (ver abajo).
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
