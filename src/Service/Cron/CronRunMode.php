<?php

declare(strict_types=1);

namespace App\Service\Cron;

/**
 * Con qué intención se lanza una tarea programada.
 *
 * Son modos y no booleanos sueltos a propósito: "previsualizar forzando" no
 * significa nada, y con flags independientes esa combinación sería
 * representable. Quien llama elige el suyo y se lee en la llamada.
 */
enum CronRunMode
{
    /** No toca nada: lista lo que haría (--dry-run). No cuenta como ejecución. */
    case Preview;

    /**
     * Ejecuta EXACTAMENTE como lo haría el reloj: si el interruptor de la tarea
     * está apagado, no se ejecuta. Es el modo del tick, y el que quiere una
     * pantalla de diagnóstico que exista para comprobar qué haría el cron.
     */
    case AsScheduled;

    /**
     * Ejecuta saltando el interruptor propio de la tarea (--force). Es el
     * puente manual mientras el reloj esté caído: lanzar el barrido de hoy
     * aunque la tarea programada esté pausada. NUNCA salta los interruptores de
     * entrega (`requires`).
     */
    case Forced;

    /**
     * Como {@see self::Forced} y además repite los efectos que ya constan
     * emitidos (--resend). Es la vía de rescate para un correo que no llegó:
     * sin ella, en un hosting sin SSH habría que borrar el apunte del guardián
     * de idempotencia a mano por phpMyAdmin. Sólo la ofrecen las tareas que
     * mandan correo, que son las únicas que declaran la opción.
     */
    case Resend;

    /**
     * ¿Es una previsualización sin efectos?
     */
    public function isPreview(): bool
    {
        return $this === self::Preview;
    }
}
