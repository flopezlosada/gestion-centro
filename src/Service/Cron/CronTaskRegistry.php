<?php

declare(strict_types=1);

namespace App\Service\Cron;

use App\Entity\CronRun;

/**
 * Lectura del manifiesto de tareas programadas.
 *
 * El manifiesto es la fuente única de verdad: de él salen el gate (qué
 * interruptores inhiben la tarea), la cadencia declarada, el plazo a partir del
 * cual se considera caída y sus dependencias. Antes esa información estaba
 * repartida entre dos `if` copiados dentro de cada comando, las líneas del
 * crontab de un servidor sin SSH y el texto de ayuda de la pantalla, así que no
 * había manera de preguntarle al sistema qué debería estar pasando.
 *
 * De DÓNDE salgan las tareas es cosa de {@see CronManifest}, no de aquí: esta
 * clase no menciona ninguna pieza de este proyecto, así que se copia tal cual a
 * otra aplicación con sólo escribirle su implementación del manifiesto.
 *
 * Este servicio sólo LEE y decide; no ejecuta nada (eso es
 * {@see \App\Command\AbstractCronCommand} y {@see CronRunner}).
 */
class CronTaskRegistry
{
    public function __construct(
        private readonly CronManifest $manifest,
        private readonly CronSchedule $schedule,
    ) {
    }

    /**
     * Todas las tareas del manifiesto, cada una con su clave incluida en los
     * metadatos (para poder pasar una tarea suelta sin perder su identidad).
     *
     * @return array<string, array<string, mixed>> Clave de tarea => metadatos + 'key'.
     */
    public function all(): array
    {
        $tasks = [];
        foreach ($this->manifest->tasks() as $key => $meta) {
            $tasks[$key] = ['key' => $key] + $meta;
        }

        return $tasks;
    }

    /**
     * Metadatos de una tarea por su clave, o null si no está en el manifiesto.
     *
     * @param string $key Clave del tipo "cron.meeting_reminders".
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        $tasks = $this->manifest->tasks();

        return isset($tasks[$key]) ? ['key' => $key] + $tasks[$key] : null;
    }

    /**
     * Metadatos de una tarea por el nombre de su comando de consola. Es la vía
     * por la que un comando en ejecución se reconoce a sí mismo en el
     * manifiesto, sin repetir su clave dentro del propio comando.
     *
     * @param string $command Nombre del comando, p. ej. "app:cron:purge-log".
     * @return array<string, mixed>|null
     */
    public function findByCommand(string $command): ?array
    {
        foreach ($this->manifest->tasks() as $key => $meta) {
            if ($meta['command'] === $command) {
                return ['key' => $key] + $meta;
            }
        }

        return null;
    }

    /**
     * ¿Está encendido el interruptor propio de la tarea?
     *
     * @param string $key Clave de la tarea.
     */
    public function isEnabled(string $key): bool
    {
        return $this->manifest->isEnabled($key);
    }

    /**
     * Motivo por el que la tarea NO debe ejecutarse, o null si puede correr.
     *
     * Distingue dos gates de naturaleza distinta, y la diferencia importa:
     *
     * - El interruptor PROPIO de la tarea (la clave del manifiesto) es "no
     *   ejecutes esto por cron". Una ejecución manual explícita ($force) lo
     *   salta: es lo que permite lanzar el barrido a mano con el cron caído.
     * - Los interruptores de `requires` (los de email) son "no entregues esto".
     *   NO se saltan ni con $force: si el envío está apagado, un lanzamiento
     *   manual tampoco debe mandar correo. Ese contrato ya estaba vigente y se
     *   conserva tal cual.
     *
     * @param string $key   Clave de la tarea.
     * @param bool   $force Ejecución manual explícita (--force).
     */
    public function inhibitedReason(string $key, bool $force = false): ?string
    {
        $meta = $this->get($key);
        if ($meta === null) {
            return null;
        }

        if (!$force && !$this->manifest->isEnabled($key)) {
            // El mensaje NO dice dónde está el interruptor, a propósito: dónde
            // vive es cosa del manifiesto de cada aplicación (una tabla, un YAML,
            // una variable de entorno) y nombrar aquí una pantalla concreta es lo
            // que acopla al núcleo con el proyecto que lo estrenó — y manda a
            // quien lea el registro a una URL que en esta aplicación no existe.
            return sprintf(
                'La tarea «%s» está desactivada. No se ejecuta.',
                $this->label($key)
            );
        }

        foreach ($meta['requires'] as $requiredKey) {
            if (!$this->manifest->isEnabled($requiredKey)) {
                return sprintf(
                    'El ajuste «%s» está desactivado. La tarea no entrega nada.',
                    $this->label($requiredKey)
                );
            }
        }

        return null;
    }

    /**
     * Etiqueta legible de un ajuste booleano; su propia clave si no la tiene
     * (no debería pasar: el test de coherencia del manifiesto lo vigila).
     *
     * @param string $key Clave del ajuste.
     */
    public function label(string $key): string
    {
        return $this->manifest->label($key);
    }

    /**
     * Cadencia declarada en castellano, para poder mostrarla ("a diario a las
     * 09:00", "los lunes a las 06:00"…).
     *
     * Sólo traduce; interpretar la cadencia es cosa de {@see CronSchedule}, que
     * es también quien decide si toca ejecutarla. Dos sitios leyendo la misma
     * estructura acabarían discrepando: se anunciaría una hora y el tick
     * dispararía a otra.
     *
     * @param string $key Clave de la tarea.
     */
    public function describeSchedule(string $key): string
    {
        $meta = $this->get($key);

        return $meta === null ? '' : $this->schedule->describe($meta['schedule']);
    }

    /**
     * ¿Le toca correr ahora a esta tarea? La pregunta que hace el tick en cada
     * pasada, para cada tarea habilitada.
     *
     * @param string       $key     Clave de la tarea.
     * @param CronRun|null $lastRun Última ejecución registrada, si hay.
     * @param \DateTimeImmutable|null $now Momento de referencia (inyectable en tests).
     */
    public function isDue(string $key, ?CronRun $lastRun, ?\DateTimeImmutable $now = null): bool
    {
        $meta = $this->get($key);

        return $meta !== null && $this->schedule->isDue($meta['schedule'], $lastRun, $now);
    }

    /**
     * ¿Se ha pasado la tarea de su plazo máximo de retraso?
     *
     * Sólo se evalúa para tareas HABILITADAS: una tarea apagada a propósito no
     * está caída, y tratarla como tal llenaría el chequeo de salud de falsas
     * alarmas. Sin ninguna ejecución registrada NO se considera retraso, porque
     * no hay referencia desde la que medir ("sin registro todavía", que es la
     * verdad, no es lo mismo que "va con retraso").
     *
     * @param string       $key     Clave de la tarea.
     * @param CronRun|null $lastRun Última ejecución registrada, si hay.
     * @param \DateTimeImmutable|null $now Momento de referencia (inyectable en tests).
     */
    public function isOverdue(string $key, ?CronRun $lastRun, ?\DateTimeImmutable $now = null): bool
    {
        $meta = $this->get($key);
        if ($meta === null || $lastRun === null || !$this->manifest->isEnabled($key)) {
            return false;
        }

        $now ??= new \DateTimeImmutable();
        $elapsedHours = ($now->getTimestamp() - $lastRun->getStartedAt()->getTimestamp()) / 3600;

        return $elapsedHours > $meta['max_delay_hours'];
    }

    /**
     * Antigüedad en palabras ("hace 3 días", "hace 5 h", "hace un momento").
     *
     * Se calcula aquí y no en la plantilla porque una fecha absoluta sola no
     * grita: lo que salta a la vista de un cron caído es el "hace 16 días".
     *
     * @param \DateTimeImmutable      $since Momento a medir.
     * @param \DateTimeImmutable|null $now   Referencia (inyectable en tests).
     */
    public function describeAge(\DateTimeImmutable $since, ?\DateTimeImmutable $now = null): string
    {
        $seconds = max(0, ($now ?? new \DateTimeImmutable())->getTimestamp() - $since->getTimestamp());

        return match (true) {
            $seconds < 60 => 'hace un momento',
            $seconds < 3600 => sprintf('hace %d min', intdiv($seconds, 60)),
            $seconds < 86400 => sprintf('hace %d h', intdiv($seconds, 3600)),
            $seconds < 172800 => 'ayer',
            default => sprintf('hace %d días', intdiv($seconds, 86400)),
        };
    }

    /**
     * Tareas de las que ésta depende y que están apagadas, con su etiqueta.
     *
     * Es la validación de coherencia del manifiesto: una tarea que se alimenta
     * de lo que deja otra corre en verde sin hacer nada cuando la de la que
     * depende está apagada. Sin este cruce, ese fallo es invisible.
     *
     * @param string $key Clave de la tarea.
     * @return list<string> Etiquetas de las dependencias apagadas.
     */
    public function unmetDependencies(string $key): array
    {
        $meta = $this->get($key);
        if ($meta === null) {
            return [];
        }

        $unmet = [];
        foreach ($meta['depends_on'] as $dependencyKey) {
            if (!$this->manifest->isEnabled($dependencyKey)) {
                $unmet[] = $this->label($dependencyKey);
            }
        }

        return $unmet;
    }
}
