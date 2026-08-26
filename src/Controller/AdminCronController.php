<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CronRun;
use App\Repository\CronRunRepository;
use App\Service\AppSettings;
use App\Service\AuditLogger;
use App\Service\Cron\CronRunMode;
use App\Service\Cron\CronRunner;
use App\Service\Cron\CronTaskRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Las tareas programadas: si están corriendo, cuándo corrió cada una y qué pasó.
 *
 * POR QUÉ EXISTE ESTA PANTALLA y no basta con el registro en la base de datos: el planificador dejó de
 * ser invisible el día que empezó a escribir `cron_run`, pero solo para quien pueda entrar en phpMyAdmin.
 * `/cron/health` contesta en JSON y está pensado para un monitor, no para una persona. Lo que hacía falta
 * es el sitio donde se ve «esta tarea no corre desde hace 16 días» sin tener que preguntárselo a nadie —
 * porque una avería que solo se detecta consultando la base de datos se detecta cuando ya ha pasado algo.
 *
 * Reservada a superusuarios, y declarado en la clase y no por acción, para que una acción añadida después
 * no pueda nacer sin protección. Es más estricto que el resto de `/admin` (donde basta escritura en
 * {@see \App\Enum\Area::ADMINISTRATION}) por lo mismo que `/admin/acceso`: esto no es negocio del centro,
 * es la infraestructura que hace que salgan los avisos, y desde aquí se puede callar a la aplicación
 * entera.
 *
 * TRES ACCIONES Y NO CINCO, al contrario que la pantalla equivalente del proyecto de origen, y no es una
 * carencia: previsualizar no existe porque ninguno de los seis barridos sabe hacerlo (envían o no envían,
 * no tienen un modo «cuéntame qué harías») y reenviar no existe porque ninguna tarea de este proyecto
 * apunta efectos en `emitted_effect` todavía. Ofrecer botones sin nada sobre lo que actuar sería peor que
 * no tenerlos: {@see CronRunner} devuelve un mensaje explicándolo, pero un botón que siempre contesta
 * «esto no se puede» enseña a desconfiar de la pantalla.
 */
#[Route('/admin/crons')]
#[IsGranted('ROLE_ADMIN')]
final class AdminCronController extends AbstractController
{
    /** Cuántas ejecuciones se listan en el historial de una tarea. */
    private const int HISTORY_LIMIT = 30;

    public function __construct(
        private readonly CronTaskRegistry $tasks,
        private readonly CronRunRepository $runs,
    ) {
    }

    /**
     * Todas las tareas con su cadencia y su última ejecución.
     *
     * @return Response la pantalla
     */
    #[Route('', name: 'admin_cron_index', methods: ['GET'])]
    public function index(): Response
    {
        $lastRuns = $this->runs->findLastRunPerTask();
        $now = new \DateTimeImmutable();
        $rows = [];
        $late = 0;
        $paused = 0;

        foreach ($this->tasks->all() as $key => $task) {
            $row = $this->describe($key, $task, $lastRuns[$key] ?? null, $now);
            $rows[] = $row;
            $late += $row['overdue'] ? 1 : 0;
            $paused += $row['enabled'] ? 0 : 1;
        }

        return $this->render('admin/cron/index.html.twig', [
            'rows' => $rows,
            'late' => $late,
            'paused' => $paused,
        ]);
    }

    /**
     * El historial de una tarea, con la salida de cada ejecución.
     *
     * @param string $key clave de la tarea en el manifiesto
     *
     * @return Response la pantalla
     */
    #[Route('/{key}', name: 'admin_cron_show', requirements: ['key' => '[a-z0-9_.]+'], methods: ['GET'])]
    public function show(string $key): Response
    {
        $task = $this->taskOr404($key);

        // Una sola consulta: el historial viene de la más nueva a la más vieja, así que la primera fila ES
        // la última ejecución — la misma que devolvería findLastRunPerTask(), que también se queda con el
        // id más alto. Pedirla aparte serían dos consultas para el mismo dato, y dos definiciones de «la
        // última» que algún día discreparían.
        $history = $this->runs->findRecentForTask($key, self::HISTORY_LIMIT);

        return $this->render('admin/cron/show.html.twig', [
            'task' => $this->describe($key, $task, $history[0] ?? null, new \DateTimeImmutable()),
            'history' => $history,
            'unmet' => $this->tasks->unmetDependencies($key),
        ]);
    }

    /**
     * Pausa o reanuda una tarea.
     *
     * Es un POST por tarea y no un formulario con todos los interruptores y un botón de guardar, al
     * contrario que {@see AdminAccessController}: allí se edita una LISTA (marcar y desmarcar gente antes
     * de confirmar) y aquí se hace UN acto deliberado sobre UNA tarea. Con el formulario grande, pausar
     * una tarea exigiría acordarse de bajar a guardar, y una tarea que se creía pausada y sigue mandando
     * avisos es justo lo que esta pantalla existe para que no pase.
     *
     * No hace falta anotar nada en el rastro de actividad: `AppSetting` es {@see \App\Contract\Auditable},
     * así que el cambio lo captura {@see \App\EventSubscriber\EntityAuditSubscriber} solo.
     *
     * @param string  $key     clave de la tarea
     * @param Request $request la petición, con `enabled` en el cuerpo
     *
     * @return Response la redirección de vuelta
     */
    #[Route('/{key}/interruptor', name: 'admin_cron_toggle', requirements: ['key' => '[a-z0-9_.]+'], methods: ['POST'])]
    public function toggle(string $key, Request $request, AppSettings $settings): Response
    {
        $this->taskOr404($key);
        $this->denyUnlessCsrfValid('cron_toggle'.$key, $request);

        $enabled = $request->request->getBoolean('enabled');
        $settings->setCronTaskEnabled($key, $enabled);

        $this->addFlash(
            'success',
            $enabled
                ? \sprintf('«%s» vuelve a ejecutarse. Si le tocaba, correrá en la próxima pasada del reloj.', $this->tasks->label($key))
                : \sprintf('«%s» queda pausada: el reloj la salta hasta que la reanudes. Los avisos que mandaría NO se acumulan.', $this->tasks->label($key))
        );

        // De vuelta a donde se pulsó: pausar desde el historial de una tarea no debe echarte al listado.
        return $request->request->getBoolean('fromDetail')
            ? $this->redirectToRoute('admin_cron_show', ['key' => $key])
            : $this->redirectToRoute('admin_cron_index');
    }

    /**
     * Ejecuta una tarea ahora mismo, saltando su interruptor.
     *
     * FORZANDO a propósito ({@see CronRunMode::Forced}): este botón es el puente para cuando el reloj está
     * caído o hay que lanzar algo a mano, y una tarea pausada que no se pudiera lanzar desde aquí obligaría
     * a reanudarla, lanzarla y volver a pausarla — tres actos donde hay uno. Lo que NUNCA salta son los
     * interruptores de ENTREGA declarados en `requires` del manifiesto: forzar significa «lánzalo aunque
     * esté pausado», nunca «entrega aunque el envío esté apagado».
     *
     * Se declara {@see CronRun::TRIGGER_MANUAL}, que es un eje distinto de forzar: si el origen se
     * dedujera de `--force`, esta ejecución se registraría como del reloj y la pantalla daría por vivo un
     * planificador parado.
     *
     * Y se anota en el rastro de actividad, que el registro de ejecuciones no cubre: `cron_run` guarda que
     * alguien la lanzó a mano, no QUIÉN. Estas tareas mandan correos y avisos push a personas reales.
     *
     * @param string  $key     clave de la tarea
     * @param Request $request la petición
     *
     * @return Response la redirección al historial, donde se ve el resultado
     */
    #[Route('/{key}/ejecutar', name: 'admin_cron_run', requirements: ['key' => '[a-z0-9_.]+'], methods: ['POST'])]
    public function run(string $key, Request $request, CronRunner $runner, AuditLogger $audit): Response
    {
        $this->taskOr404($key);
        $this->denyUnlessCsrfValid('cron_run'.$key, $request);

        $result = $runner->run($key, CronRunMode::Forced, trigger: CronRun::TRIGGER_MANUAL);

        if (null !== $result->blocked) {
            $this->addFlash('warning', $result->blocked);

            return $this->redirectToRoute('admin_cron_show', ['key' => $key]);
        }

        $audit->log(
            'cron.run_manually',
            'CronTask',
            $key,
            \sprintf('Ejecutó «%s» a mano (código %d).', $result->label, (int) $result->exitCode)
        );

        $this->addFlash(
            $result->isSuccessful() ? 'success' : 'error',
            $result->isSuccessful()
                ? \sprintf('«%s» ejecutada. Abajo, en la primera línea, lo que hizo.', $result->label)
                : \sprintf('«%s» ha fallado (código %d). La salida completa está abajo, en la primera línea.', $result->label, (int) $result->exitCode)
        );

        return $this->redirectToRoute('admin_cron_show', ['key' => $key]);
    }

    /**
     * Lo que la pantalla necesita saber de una tarea.
     *
     * @param string               $key     clave de la tarea
     * @param array<string, mixed> $task    metadatos del manifiesto
     * @param CronRun|null         $lastRun su última ejecución, si hay
     * @param \DateTimeImmutable   $now     momento de referencia
     *
     * @return array{key: string, label: string, schedule: string, enabled: bool, maxDelayHours: int, lastRun: CronRun|null, age: string|null, overdue: bool}
     */
    private function describe(string $key, array $task, ?CronRun $lastRun, \DateTimeImmutable $now): array
    {
        return [
            'key' => $key,
            'label' => $this->tasks->label($key),
            'schedule' => $this->tasks->describeSchedule($key),
            'enabled' => $this->tasks->isEnabled($key),
            'maxDelayHours' => (int) $task['max_delay_hours'],
            'lastRun' => $lastRun,
            // La antigüedad en palabras y no solo la fecha: lo que salta a la vista de un cron caído es el
            // «hace 16 días», no un 10/08/2026 que hay que restar mentalmente.
            'age' => null === $lastRun ? null : $this->tasks->describeAge($lastRun->getStartedAt(), $now),
            'overdue' => $this->tasks->isOverdue($key, $lastRun, $now),
        ];
    }

    /**
     * Los metadatos de una tarea del manifiesto, o 404.
     *
     * El manifiesto ES la lista blanca: sin esto, la clave de la URL llegaría a {@see CronRunner} y la
     * pantalla sería una consola remota con el nombre de la tarea por parámetro.
     *
     * @param string $key clave de la tarea
     *
     * @return array<string, mixed> los metadatos
     */
    private function taskOr404(string $key): array
    {
        return $this->tasks->get($key) ?? throw $this->createNotFoundException(\sprintf('No hay ninguna tarea programada «%s».', $key));
    }

    /**
     * Rechaza la petición si no trae el token CSRF de esa acción.
     *
     * @param string  $id      identificador del token
     * @param Request $request la petición
     */
    private function denyUnlessCsrfValid(string $id, Request $request): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
    }
}
