<?php

declare(strict_types=1);

namespace App\Service\Cron;

/**
 * Resultado de lanzar una tarea programada a mano desde la web
 * ({@see CronRunner::run()}).
 *
 * `blocked` recoge el caso "no se ha llegado a ejecutar y hay que explicar por
 * qué" (p. ej. la tarea necesita destinatario y quien la lanza no tiene email).
 * Se modela como dato y no como excepción porque para la pantalla es un aviso
 * normal, no un error del programa.
 */
final class CronRunResult
{
    /**
     * @param string      $taskKey  Clave de la tarea en el manifiesto.
     * @param string      $command  Comando de consola ejecutado.
     * @param string      $label    Etiqueta legible de la tarea.
     * @param CronRunMode $mode     Con qué intención se lanzó.
     * @param int|null    $exitCode Código de salida, o null si no se ejecutó.
     * @param string      $output   Salida capturada del comando.
     * @param string|null $blocked  Motivo por el que no se ejecutó, o null.
     */
    public function __construct(
        public readonly string $taskKey,
        public readonly string $command,
        public readonly string $label,
        public readonly CronRunMode $mode,
        public readonly ?int $exitCode,
        public readonly string $output = '',
        public readonly ?string $blocked = null,
    ) {
    }

    /**
     * ¿Se ejecutó y terminó bien?
     */
    public function isSuccessful(): bool
    {
        return $this->blocked === null && $this->exitCode === 0;
    }

    /**
     * ¿Fue una previsualización?
     */
    public function isPreview(): bool
    {
        return $this->mode->isPreview();
    }
}
