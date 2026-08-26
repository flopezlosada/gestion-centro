<?php

declare(strict_types=1);

namespace App\Service\Cron;

use App\Command\AbstractCronCommand;
use App\Entity\CronRun;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Lanza una tarea programada a mano, en proceso.
 *
 * Se ejecuta con la API de consola de Symfony en lugar de `exec`/`proc_open`
 * porque el hosting compartido puede tenerlos deshabilitados. Es por donde entra
 * el tick ({@see CronTick}) y por donde entraría un botón de "lanzar ahora", sin
 * que ninguno de los dos tenga que saber cómo se aplica el gate.
 *
 * La lista blanca de lo que se puede lanzar ES el manifiesto
 * ({@see CronManifest}): una clave que no esté declarada no se ejecuta.
 *
 * Lo que NO decide es la INTENCIÓN con la que se lanza: forzar saltando el
 * interruptor de la tarea, ejecutar exactamente como lo haría el reloj o
 * previsualizar son cosas distintas y las declara quien llama. De ahí
 * {@see CronRunMode}.
 *
 * NO evalúa los interruptores: eso lo hace {@see AbstractCronCommand}, que cubre
 * también las ejecuciones por consola del cron — las únicas que hay en
 * producción.
 */
class CronRunner
{
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly CronTaskRegistry $tasks,
    ) {
    }

    /**
     * Ejecuta una tarea del manifiesto y devuelve su resultado.
     *
     * @param string      $taskKey    Clave declarada en el manifiesto ({@see CronManifest::tasks()}).
     * @param CronRunMode $mode       Previsualizar, ejecutar como el reloj, forzar o reenviar.
     * @param string|null $adminEmail Email de quien lanza, para las tareas que exigen destinatario.
     * @param string      $trigger    Quién dispara: uno de los CronRun::TRIGGER_* (a mano desde
     *                                la web, el tick horario o el crontab del hosting).
     * @throws \InvalidArgumentException Si la clave no está en el manifiesto.
     */
    public function run(
        string $taskKey,
        CronRunMode $mode,
        ?string $adminEmail = null,
        string $trigger = CronRun::TRIGGER_MANUAL,
    ): CronRunResult {
        $task = $this->tasks->get($taskKey)
            ?? throw new \InvalidArgumentException(sprintf('Tarea desconocida "%s".', $taskKey));

        $label = $this->tasks->label($taskKey);

        $application = new Application($this->kernel);
        $application->setAutoExit(false);

        // El comando se resuelve ANTES de montar los argumentos para poder
        // preguntarle qué opciones acepta: pasarle una que no declara aborta la
        // ejecución con una excepción de consola, y eso no debe depender de que
        // la pantalla ofrezca el botón correcto.
        $command = $this->resolveCommand($application, $task['command'], $taskKey);

        if ($mode === CronRunMode::Resend && !$command->getDefinition()->hasOption('resend')) {
            return new CronRunResult(
                $taskKey,
                $task['command'],
                $label,
                $mode,
                null,
                '',
                'Esta tarea no produce efectos repetibles, así que no hay nada que reenviar.'
            );
        }

        // La misma guarda para previsualizar, por la misma razón: pasarle a un
        // comando una opción que no declara aborta con una excepción de consola,
        // y eso acabaría en el registro como "el comando lanzó una excepción",
        // que no es lo que pasó. En este proyecto NINGUNA tarea sabe
        // previsualizar —los notificadores envían o no envían, no tienen un modo
        // "cuéntame qué harías"—, así que sin esto un botón de previsualización
        // fallaría con un mensaje que no explica nada.
        if ($mode->isPreview() && !$command->getDefinition()->hasOption('dry-run')) {
            return new CronRunResult(
                $taskKey,
                $task['command'],
                $label,
                $mode,
                null,
                '',
                'Esta tarea no sabe previsualizar: hace su trabajo o no lo hace. Lánzala de verdad o déjala al reloj.'
            );
        }

        $args = ['command' => $task['command']] + match ($mode) {
            CronRunMode::Preview => ['--dry-run' => true],
            CronRunMode::AsScheduled => [],
            CronRunMode::Forced => ['--force' => true],
            CronRunMode::Resend => ['--force' => true, '--resend' => true],
        };

        // Las tareas que envían a una persona concreta (un resumen a dirección,
        // un informe) se dirigen a QUIEN PULSA el botón, para no mandar correo a
        // terceros desde una prueba manual.
        //
        // Cuando dispara el reloj no hay nadie que pulse, y entonces no se pasa
        // --to: cada comando resuelve su destinatario de donde le toque. Por eso
        // el aviso de "falta destinatario" es sólo para el camino manual; en el
        // del reloj lo dice el propio comando, que es quien sabe qué mira.
        if (!$mode->isPreview() && ($task['needs_recipient'] ?? false) && $trigger === CronRun::TRIGGER_MANUAL) {
            if ($adminEmail === null || trim($adminEmail) === '') {
                return new CronRunResult(
                    $taskKey,
                    $task['command'],
                    $label,
                    $mode,
                    null,
                    '',
                    'Esta tarea necesita un destinatario y tu usuario no tiene email configurado. Usa la previsualización o configura tu email.'
                );
            }
            $args['--to'] = trim($adminEmail);
        }

        // Volúmenes pequeños, pero el envío por SMTP y por push puede tardar:
        // que no lo corte PHP a mitad.
        @set_time_limit(0);

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

        // Que la ejecución quede registrada con su origen real. Es un eje
        // distinto de --force (que sólo dice si se salta el interruptor): un
        // lanzamiento manual puede no forzar y seguir siendo manual, y si el
        // origen se dedujera de --force el registro daría por vivo un reloj
        // parado. El tick pasa por aquí igual que un botón, pero declarándose
        // como el reloj que es.
        $command->markTriggeredBy($trigger);

        try {
            $exitCode = $application->run(new ArrayInput($args), $output);
            $text = $output->fetch();
        } catch (\Throwable $e) {
            $exitCode = 1;
            $text = sprintf("El comando lanzó una excepción:\n\n%s: %s", $e::class, $e->getMessage());
        }

        return new CronRunResult($taskKey, $task['command'], $label, $mode, $exitCode, trim($text));
    }

    /**
     * El comando de consola de una tarea, ya desenvuelto y comprobado.
     *
     * OJO: los comandos con descripción en #[AsCommand] se registran envueltos
     * en LazyCommand (Symfony\Component\Console\DependencyInjection\AddConsoleCommandPass),
     * así que hay que desenvolverlos o el instanceof falla en silencio y el
     * origen del disparo se registraría mal.
     *
     * @param Application $application Aplicación de consola ya construida.
     * @param string      $commandName Nombre del comando de consola.
     * @param string      $taskKey     Clave de la tarea, sólo para el mensaje de error.
     * @throws \LogicException Si el comando no hereda de la base de las tareas.
     */
    private function resolveCommand(Application $application, string $commandName, string $taskKey): AbstractCronCommand
    {
        $command = $application->find($commandName);
        if ($command instanceof LazyCommand) {
            $command = $command->getCommand();
        }

        if (!$command instanceof AbstractCronCommand) {
            // El manifiesto sólo declara tareas que heredan de la base (lo vigila
            // CronManifestTest); si no, es un fallo y vale más que se note.
            throw new \LogicException(sprintf(
                'El comando "%s" de la tarea "%s" no hereda de AbstractCronCommand.',
                $commandName,
                $taskKey
            ));
        }

        return $command;
    }
}
