<?php

declare(strict_types=1);

namespace App\Service\Cron;

use App\Entity\CronRun;
use App\Repository\CronRunRepository;
use Psr\Log\LoggerInterface;

/**
 * El latido del planificador: mira qué tareas tocan y las ejecuta.
 *
 * Es lo que sustituye al crontab del hosting. El reloj externo llama SIEMPRE a
 * lo mismo —"es la hora"— y aquí se decide qué hacer con esa llamada, cruzando
 * el manifiesto con el registro de ejecuciones. Ni el reloj sabe qué tareas
 * existen ni hay que pedirle nada a nadie para añadir una nueva.
 *
 * Vive separado del controlador a propósito: así se puede ejercitar entero sin
 * HTTP, que es donde de verdad se comprueba que un tick repetido no duplica
 * trabajo y que un tick perdido se recupera.
 *
 * NO decide si una tarea puede entregar ni la ejecuta a mano: sólo elige. El
 * gate de interruptores, el cerrojo y el registro siguen viviendo en
 * {@see \App\Command\AbstractCronCommand}, que es por donde pasan también el
 * cron viejo y los botones de la pantalla.
 */
class CronTick
{
    public function __construct(
        private readonly CronTaskRegistry $tasks,
        private readonly CronRunRepository $runs,
        private readonly CronRunner $runner,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Ejecuta las tareas que toquen y devuelve el resumen de lo hecho.
     *
     * Las tareas se lanzan una detrás de otra, en el orden del manifiesto. No se
     * paralelizan ni falta: son unas pocas, y en serie el orden declarado sirve
     * de dependencia natural para las tareas que se alimenten unas de otras.
     *
     * Que una tarea falle no detiene a las demás: cada una registra su resultado
     * y el tick sigue. Lo contrario haría que un fallo en la primera dejara sin
     * ejecutar el resto sin que nadie lo supiera.
     *
     * @param \DateTimeImmutable|null $now Momento de referencia (inyectable en tests).
     * @return array<string, string> Clave de tarea => qué se hizo con ella.
     */
    public function run(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $lastRuns = $this->runs->findLastRunPerTask();
        $done = [];

        foreach ($this->tasks->all() as $key => $task) {
            // Una tarea apagada no "toca": está apagada. Se comprueba aquí y no
            // sólo en el gate del comando para no llenar el registro de filas
            // "disabled" cada hora — con el tick, eso serían 24 al día por
            // tarea apagada, y la pantalla dejaría de decir nada útil.
            if (!$this->tasks->isEnabled($key)) {
                continue;
            }

            if (!$this->tasks->isDue($key, $lastRuns[$key] ?? null, $now)) {
                continue;
            }

            $done[$key] = $this->runTask($key);
        }

        return $done;
    }

    /**
     * Lanza una tarea y devuelve en una línea qué pasó, sin dejar que una
     * excepción se lleve por delante el resto del tick.
     *
     * @param string $key Clave de la tarea.
     */
    private function runTask(string $key): string
    {
        try {
            // TRIGGER_TICK y no TRIGGER_SCHEDULE: el registro tiene que poder
            // decir si una tarea la hizo el tick o el crontab viejo del hosting.
            // Mientras los dos relojes convivan es la ÚNICA forma de saber si el
            // nuevo funciona, porque el viejo dispara en punto, llega antes y
            // deja al tick sin nada que hacer.
            $result = $this->runner->run($key, CronRunMode::AsScheduled, null, CronRun::TRIGGER_TICK);

            if ($result->blocked !== null) {
                return $result->blocked;
            }

            return sprintf('ejecutada (código %d)', (int) $result->exitCode);
        } catch (\Throwable $e) {
            // El registro de la ejecución ya lo ha anotado como fallo desde el
            // comando; aquí sólo se evita que tumbe el tick entero.
            $this->logger->error('El tick falló al ejecutar la tarea {task}: {error}', [
                'task' => $key,
                'error' => $e->getMessage(),
            ]);

            return sprintf('excepción: %s', $e->getMessage());
        }
    }
}
