<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CronRun;
use App\Service\Cron\Adapter\CentreCronManifest;
use App\Service\Cron\CronRunMode;
use App\Service\Cron\CronRunner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Los DOS endpoints de la vía antigua: un crontab del hosting que llame por HTTP con `curl` porque su
 * intérprete de PHP no sirve para `bin/console` (el de cdmon es 8.1 y el proyecto exige 8.2+).
 *
 * SIGUEN AQUÍ a propósito, aunque el planificador ({@see CronTickController}) los deje redundantes: son
 * el plan B si el reloj externo no cuaja, y un reloj viejo que todavía no se ha jubilado es una red, no
 * una deuda. Están cerrados con `CRON_SECRET`, que hoy no está puesto en ningún servidor: sin él estas
 * dos rutas responden 403 y no hacen nada.
 *
 * ⚠️ LO QUE CAMBIÓ AL LLEGAR EL PLANIFICADOR, y es la razón de este fichero tal como está: antes cada
 * endpoint llamaba a los notificadores A PELO. Eso los dejaba fuera del gate, del CERROJO y del
 * registro de ejecuciones, y con el tick funcionando a la vez eso deja de ser inocuo — dos caminos que
 * no comparten cerrojo pueden barrer lo mismo al mismo tiempo, y cada barrido se protege con un `if`
 * sobre su propio sello, que es justo el patrón que pierde la carrera (los dos leen que falta el aviso
 * y los dos lo mandan). Ahora las dos rutas ejecutan las MISMAS tareas del manifiesto, por el mismo
 * runner, así que los dos relojes vuelven a ser seguros a la vez y estas llamadas también quedan
 * registradas.
 *
 * Se ejecuta en modo {@see CronRunMode::AsScheduled} y declarándose {@see CronRun::TRIGGER_SCHEDULE}:
 * es lo que es, el crontab del hosting. Y no consulta la cadencia del manifiesto —ejecuta lo que se le
 * pide— porque en este camino quien decide cuándo toca es el crontab, no la aplicación.
 */
final class CronController extends AbstractController
{
    /**
     * Las cuatro tareas con antelación en MINUTOS: los avisos de la agenda personal, el «apunta las
     * ausencias en RAICES» de quien entra a una guardia, el doble recordatorio de guardia y el aviso de
     * una reunión que empieza.
     *
     * Comparten un solo endpoint por lo mismo que siempre: todas quieren la misma cadencia, y separarlas
     * haría que cada barrido nuevo dependiera de que alguien acordase otra línea de crontab con el
     * hosting — un «no sale ni un aviso» silencioso si no lo hace, que ya pasó con RAICES y otra vez con
     * guardias y reuniones.
     */
    private const array MINUTE_LEVEL_TASKS = [
        CentreCronManifest::CRON_EVENT_REMINDERS,
        CentreCronManifest::CRON_GUARDIA_RAICES_REMINDERS,
        CentreCronManifest::CRON_GUARDIA_DUTY_REMINDERS,
        CentreCronManifest::CRON_MEETING_REMINDERS,
    ];

    public function __construct(
        private readonly CronRunner $runner,
        #[Autowire('%env(CRON_SECRET)%')]
        private readonly string $cronSecret,
    ) {
    }

    /**
     * Una vez al día: avisos de tareas próximas, escalada de las que están fuera de plazo y retirada de
     * los avisos caducados. Y la poda del propio registro de ejecuciones, que también es diaria y que
     * sin esto quedaría sin disparar en este camino — creciendo sin freno.
     *
     * @param Request $request la petición del crontab
     *
     * @return Response resumen de una línea por tarea, para que quede en el log del crontab
     */
    #[Route('/cron/task-reminders', name: 'cron_task_reminders', methods: ['GET'])]
    public function taskReminders(Request $request): Response
    {
        $this->denyUnlessCronToken($request);

        return $this->runTasks([
            CentreCronManifest::CRON_TASK_REMINDERS,
            CentreCronManifest::CRON_PURGE_LOG,
        ]);
    }

    /**
     * Cada pocos minutos: los cuatro barridos con antelación de minutos.
     *
     * Ninguno detiene a los demás si falla, al contrario que antes. El cambio es deliberado: el runner
     * registra cada tarea con su propio resultado, así que un fallo persistente ya se ve en el registro
     * y en `/cron/health` sin necesidad de que se lleve por delante las otras tres. Antes, dejar caer la
     * excepción era la única forma de que el fallo se notara en algún sitio.
     *
     * @param Request $request la petición del crontab
     *
     * @return Response resumen de una línea por tarea
     */
    #[Route('/cron/event-reminders', name: 'cron_event_reminders', methods: ['GET'])]
    public function eventReminders(Request $request): Response
    {
        $this->denyUnlessCronToken($request);

        return $this->runTasks(self::MINUTE_LEVEL_TASKS);
    }

    /**
     * Ejecuta una lista de tareas del manifiesto y devuelve en texto plano qué pasó con cada una.
     *
     * El código HTTP es 200 salvo que alguna falle: un monitor de cron que vigile estas URLs necesita
     * que un fallo se vea en el código, no solo en el cuerpo.
     *
     * @param list<string> $taskKeys claves de las tareas a ejecutar, en orden
     *
     * @return Response el resumen y el código que corresponda
     */
    private function runTasks(array $taskKeys): Response
    {
        $lines = [];
        $failed = false;

        foreach ($taskKeys as $key) {
            $result = $this->runner->run($key, CronRunMode::AsScheduled, trigger: CronRun::TRIGGER_SCHEDULE);

            if (null !== $result->blocked) {
                $lines[] = \sprintf('%s: %s', $result->label, $result->blocked);
                continue;
            }

            $failed = $failed || !$result->isSuccessful();
            $lines[] = \sprintf('%s: código %d. %s', $result->label, (int) $result->exitCode, $result->output);
        }

        return new Response(
            implode("\n", $lines)."\n",
            $failed ? Response::HTTP_INTERNAL_SERVER_ERROR : Response::HTTP_OK,
            ['Content-Type' => 'text/plain; charset=utf-8'],
        );
    }

    /**
     * Rejects the request unless it carries the shared cron secret. Compared in constant time, and
     * fail-closed: with no CRON_SECRET configured, nothing is callable.
     *
     * @param Request $request the incoming cron request
     */
    private function denyUnlessCronToken(Request $request): void
    {
        if ('' === $this->cronSecret || !hash_equals($this->cronSecret, (string) $request->query->get('token'))) {
            throw new AccessDeniedHttpException('Token de cron inválido.');
        }
    }
}
