<?php

declare(strict_types=1);

namespace App\Service\Cron;

use App\Entity\CronRun;

/**
 * Interpreta las cadencias declaradas en el manifiesto: cómo se dicen en
 * castellano y, sobre todo, si a una tarea LE TOCA correr ahora.
 *
 * Es el corazón del tick. Hasta ahora la cadencia era decorativa —se pintaba en
 * la pantalla y servía para medir el retraso— y quien disparaba de verdad era el
 * crontab del hosting. Desde el tick, esto es lo que manda.
 *
 * LA REGLA, y no es "¿son las seis de un lunes?". Esa pregunta obliga a que el
 * reloj sea puntual: si el tick de las 06:00 se pierde, la tarea no corre esa
 * semana. La pregunta correcta es **"¿ha corrido desde la última vez que le
 * tocaba?"**. Con eso, un tick perdido lo recupera el siguiente, un tick
 * repetido no hace nada, y da igual que el reloj llegue tarde — que es el
 * principio del que sale todo este diseño.
 *
 * REINTENTO TRAS UN FALLO. Si el último intento falló, se vuelve a intentar en
 * el siguiente tick. Así un SMTP caído cinco minutos no deja a nadie sin aviso
 * hasta mañana. Es seguro porque los efectos externos son idempotentes
 * ({@see EffectLedger}) y porque el cerrojo impide que dos intentos se pisen.
 *
 * No se le pone tope, y es una decisión, no un olvido. Un tope por número de
 * intentos obligaría a leer el historial en vez del último registro, y un tope
 * por tiempo resulta inoperante: el manifiesto exige que el plazo máximo de
 * retraso sea mayor que el propio período (si no, daría falsas alarmas), así
 * que cualquier ventana construida sobre él llega hasta la siguiente ocurrencia,
 * que reactiva la tarea de todos modos. El precio es que una tarea rota se
 * reintenta cada tick hasta que alguien la arregla; a cambio, en cuanto se
 * arregla la causa se recupera sola sin que nadie tenga que relanzarla, y
 * mientras tanto sale en rojo en la pantalla, que es donde debe verse.
 *
 * LA ZONA HORARIA la pone el manifiesto ({@see CronManifest::timezone()}), no
 * esta clase ni el `php.ini`. Aquí no hay ningún sitio dónde escribir "Madrid":
 * dónde vive la gente de una aplicación es dato suyo, no del planificador. Y
 * declararla en vez de heredarla evita depender de la configuración de un
 * servidor compartido: si una migración lo dejara en UTC, "las 06:00" del
 * manifiesto pasarían a ser las 08:00 reales en verano y los avisos del día
 * llegarían tarde sin que nadie entendiera por qué.
 */
class CronSchedule
{
    /** Días de la semana en ISO-8601 (1 = lunes), para describir cadencias. */
    private const WEEKDAYS = [
        1 => 'lunes',
        2 => 'martes',
        3 => 'miércoles',
        4 => 'jueves',
        5 => 'viernes',
        6 => 'sábado',
        7 => 'domingo',
    ];

    public function __construct(
        private readonly CronManifest $manifest,
    ) {
    }

    /**
     * ¿Le toca correr a esta tarea?
     *
     * @param array<string, mixed> $schedule Cadencia declarada de la tarea.
     * @param CronRun|null         $lastRun  Última ejecución registrada, si hay.
     * @param \DateTimeImmutable|null $now   Momento de referencia (inyectable en tests).
     */
    public function isDue(array $schedule, ?CronRun $lastRun, ?\DateTimeImmutable $now = null): bool
    {
        $now = $this->inZone($now ?? new \DateTimeImmutable());

        // Nunca ha corrido: le toca. Es lo que estrena una tarea recién añadida,
        // y es seguro porque las tareas son idempotentes por estado (si no hay
        // trabajo, salen con "nada que hacer").
        if ($lastRun === null) {
            return true;
        }

        $startedAt = $this->inZone($lastRun->getStartedAt());

        // Las cadencias por intervalo no tienen una hora a la que "tocar": se
        // miden desde la última ejecución. Es la forma que necesitan las tareas
        // de otros proyectos ("cada cinco minutos"); aquí no se usa ninguna.
        if (($schedule['freq'] ?? null) === 'interval') {
            $minutes = max(1, (int) ($schedule['minutes'] ?? 60));

            return $now->getTimestamp() - $startedAt->getTimestamp() >= $minutes * 60;
        }

        $occurrence = $this->lastOccurrence($schedule, $now);
        if ($occurrence === null) {
            return false;
        }

        // Lo normal: no ha corrido desde que le tocaba.
        if ($startedAt < $occurrence) {
            return true;
        }

        // Ya corrió en esta ocurrencia, pero falló: se reintenta.
        return $lastRun->getStatus() === CronRun::STATUS_FAILED;
    }

    /**
     * La última vez que la tarea debería haber corrido, en el pasado o ahora
     * mismo. Devuelve null si la cadencia no se puede situar en el calendario
     * (las de intervalo).
     *
     * @param array<string, mixed>    $schedule Cadencia declarada.
     * @param \DateTimeImmutable      $now      Momento de referencia, ya en zona.
     */
    public function lastOccurrence(array $schedule, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $at = $this->atDeclaredTime($now, $schedule);

        return match ($schedule['freq'] ?? null) {
            // Hoy a su hora; si todavía no ha llegado, la de ayer.
            'daily' => $at <= $now ? $at : $at->modify('-1 day'),

            // El día de la semana que toque, a su hora; si cae en el futuro,
            // el de la semana pasada.
            'weekly' => $this->rewindIfFuture(
                $this->atDeclaredTime($now->modify(sprintf('monday this week +%d days', ((int) $schedule['dow']) - 1)), $schedule),
                $now,
                '-7 days'
            ),

            // El día del mes que toque; si cae en el futuro, el del mes pasado.
            // El manifiesto limita el día a 28 para que exista en todos los meses.
            'monthly' => $this->rewindIfFuture(
                $this->atDeclaredTime($now->setDate((int) $now->format('Y'), (int) $now->format('n'), (int) $schedule['dom']), $schedule),
                $now,
                '-1 month'
            ),

            default => null,
        };
    }

    /**
     * Cadencia en castellano, para pintarla en la pantalla ("a diario a las
     * 09:00", "los lunes a las 06:00"…).
     *
     * @param array<string, mixed> $schedule Cadencia declarada.
     */
    public function describe(array $schedule): string
    {
        $at = sprintf('a las %02d:%02d', $schedule['hour'] ?? 0, $schedule['minute'] ?? 0);

        return match ($schedule['freq'] ?? null) {
            'daily' => sprintf('a diario %s', $at),
            'weekly' => sprintf('los %s %s', self::WEEKDAYS[$schedule['dow']], $at),
            'monthly' => sprintf('el día %d de cada mes %s', $schedule['dom'], $at),
            'interval' => $this->describeInterval((int) ($schedule['minutes'] ?? 60)),
            default => $at,
        };
    }

    /**
     * Un momento del calendario llevado a la hora declarada en la cadencia.
     *
     * @param \DateTimeImmutable   $moment   Día sobre el que fijar la hora.
     * @param array<string, mixed> $schedule Cadencia declarada.
     */
    private function atDeclaredTime(\DateTimeImmutable $moment, array $schedule): \DateTimeImmutable
    {
        return $moment->setTime((int) ($schedule['hour'] ?? 0), (int) ($schedule['minute'] ?? 0));
    }

    /**
     * Devuelve la ocurrencia tal cual si ya ha pasado, o la anterior si cae en
     * el futuro.
     *
     * @param \DateTimeImmutable $occurrence Ocurrencia candidata.
     * @param \DateTimeImmutable $now        Momento de referencia.
     * @param string             $rewind     Salto atrás ("-7 days", "-1 month").
     */
    private function rewindIfFuture(\DateTimeImmutable $occurrence, \DateTimeImmutable $now, string $rewind): \DateTimeImmutable
    {
        return $occurrence <= $now ? $occurrence : $occurrence->modify($rewind);
    }

    /**
     * "cada 5 minutos", "cada hora", "cada 2 horas".
     *
     * @param int $minutes Minutos entre ejecuciones.
     */
    private function describeInterval(int $minutes): string
    {
        if ($minutes % 60 !== 0) {
            return sprintf('cada %d minutos', $minutes);
        }

        $hours = intdiv($minutes, 60);

        return $hours === 1 ? 'cada hora' : sprintf('cada %d horas', $hours);
    }

    /**
     * Lleva un instante a la zona horaria del planificador, para que todas las
     * comparaciones se hagan en la misma.
     *
     * @param \DateTimeInterface $moment Instante a convertir.
     */
    private function inZone(\DateTimeInterface $moment): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($moment)
            ->setTimezone(new \DateTimeZone($this->manifest->timezone()));
    }
}
