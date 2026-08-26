<?php

declare(strict_types=1);

namespace App\Service\Cron;

/**
 * De dónde salen las tareas programadas y sus interruptores.
 *
 * ES LA ÚNICA COSTURA del planificador con la aplicación que lo hospeda. Todo lo
 * demás —el registro de ejecuciones, el cerrojo, el guardián de idempotencia, la
 * lectura de cadencias— es genérico y se copia tal cual a otro proyecto; lo que
 * cambia de una aplicación a otra es dónde viven sus tareas y cómo se consulta
 * si un ajuste está encendido.
 *
 * En este proyecto la implementa {@see Adapter\CentreCronManifest}, que declara
 * el catálogo de tareas y lee los interruptores de la tabla de ajustes. Otro
 * proyecto puede servir su manifiesto desde un YAML o desde donde quiera: el
 * núcleo no se entera.
 *
 * FORMA DE LOS METADATOS de cada tarea (el contrato que el núcleo espera):
 *
 * - `command`: nombre del comando de consola.
 * - `schedule`: `['freq' => 'daily'|'weekly'|'monthly', 'hour' => int, ...]`,
 *   con `dow` (1 = lunes) en las semanales y `dom` en las mensuales.
 * - `max_delay_hours`: a partir de cuántas horas sin correr se considera caída.
 * - `requires`: claves de ajustes de ENTREGA que la habilitan; no las salta ni
 *   una ejecución manual forzada.
 * - `depends_on`: claves de otras tareas de las que depende.
 *
 * Los campos que sólo usa la interfaz de este proyecto (`confirm`, `dry`,
 * `needs_recipient`) no los mira el núcleo: viajan en el mismo array y cada
 * aplicación pone los suyos.
 */
interface CronManifest
{
    /**
     * Todas las tareas declaradas: clave de tarea => metadatos.
     *
     * @return array<string, array<string, mixed>>
     */
    public function tasks(): array;

    /**
     * ¿Está encendido un ajuste booleano? Vale tanto para el interruptor propio
     * de una tarea (cuya clave es la de la tarea) como para los de entrega
     * declarados en `requires`.
     *
     * @param string $settingKey Clave del ajuste.
     */
    public function isEnabled(string $settingKey): bool;

    /**
     * Etiqueta legible de un ajuste, para los mensajes y la pantalla. Si no la
     * tiene, devolver la propia clave es respuesta válida.
     *
     * @param string $settingKey Clave del ajuste.
     */
    public function label(string $settingKey): string;

    /**
     * Zona horaria en la que hay que entender las horas del manifiesto.
     *
     * Va aquí porque una hora declarada sin zona está incompleta: "las 06:00"
     * no significa nada hasta saber de dónde. Y va en el manifiesto y no en el
     * núcleo porque es un dato de la aplicación —dónde vive su gente—, no del
     * planificador: el centro vive en hora peninsular, otra aplicación estará en
     * otro sitio.
     *
     * Declararla también evita depender del `php.ini` del servidor, que en un
     * hosting compartido no se controla.
     *
     * @return string Identificador de zona, p. ej. "Europe/Madrid".
     */
    public function timezone(): string;
}
