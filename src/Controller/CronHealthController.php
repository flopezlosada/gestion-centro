<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CronRunRepository;
use App\Service\Cron\CronTaskRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Chequeo de salud del planificador, de sólo lectura.
 *
 * Responde 200 si todas las tareas ENCENDIDAS están dentro de su plazo, y 503 si
 * alguna se ha pasado. Está pensado para que lo vigile un monitor externo
 * gratuito, de esos que avisan por correo cuando algo deja de responder.
 *
 * Cubre además el único caso que ninguna otra pieza puede cubrir: que la web
 * entera esté caída. Un planificador que se vigila a sí mismo no puede avisar de
 * que no está funcionando.
 *
 * SIN TOKEN, a diferencia del tick, y es deliberado: no ejecuta nada, no recibe
 * parámetros y no expone ningún dato personal — sólo nombres de tareas internas
 * y si van con retraso. Pedir un token aquí obligaría a configurarlo en el
 * monitor, y un chequeo que cuesta configurar acaba sin configurarse.
 *
 * Se evalúan sólo las tareas encendidas: una apagada a propósito no está caída,
 * y tratarla como tal llenaría el monitor de falsas alarmas hasta que nadie le
 * hiciera caso — que es exactamente cómo se pierden dos semanas sin enterarse.
 */
class CronHealthController
{
    public function __construct(
        private readonly CronTaskRegistry $tasks,
        private readonly CronRunRepository $runs,
    ) {
    }

    /**
     * Estado de las tareas programadas, en JSON y en el código HTTP.
     */
    #[Route('/cron/health', name: 'cron_health', methods: ['GET'])]
    public function health(): Response
    {
        $lastRuns = $this->runs->findLastRunPerTask();
        $now = new \DateTimeImmutable();
        $late = [];
        $checked = [];

        foreach ($this->tasks->all() as $key => $task) {
            if (!$this->tasks->isEnabled($key)) {
                continue;
            }

            $lastRun = $lastRuns[$key] ?? null;
            $overdue = $this->tasks->isOverdue($key, $lastRun, $now);
            $checked[$key] = [
                'schedule' => $this->tasks->describeSchedule($key),
                'last_run' => $lastRun?->getStartedAt()->format(\DateTimeInterface::ATOM),
                'status' => $lastRun?->getStatus(),
                'overdue' => $overdue,
            ];

            if ($overdue) {
                $late[] = $key;
            }
        }

        return new JsonResponse(
            [
                'ok' => $late === [],
                'late' => $late,
                'tasks' => $checked,
            ],
            $late === [] ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}
