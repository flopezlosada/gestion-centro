<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Command\AbstractCronCommand;
use App\Service\Cron\Adapter\CentreCronManifest;
use App\Service\Cron\CronTaskRegistry;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;

/**
 * Coherencia del manifiesto de tareas programadas ({@see CentreCronManifest::TASKS}).
 *
 * El manifiesto es la fuente única de la que salen el gate, la cadencia y los avisos, así que una
 * entrada mal declarada no da un error visible: da una tarea que no se inhibe, o que nadie vigila, o
 * un botón que revienta. Estos tests son el guardián de esa fuente, y por eso comprueban también la
 * relación INVERSA — que ningún comando de cron se quede fuera del manifiesto, que sería exactamente
 * el agujero que todo esto cierra.
 *
 * SE LEE POR LA INTERFAZ ({@see \App\Service\Cron\CronManifest::tasks()}) y no por la constante, y no
 * es un detalle de estilo: leyendo la constante, PHPStan resuelve sus valores literales y demuestra
 * estáticamente que las comprobaciones de forma son ciertas — con lo que dejan de comprobar nada y el
 * análisis las marca como tautologías. Por la interfaz se comprueba lo que el manifiesto SIRVE, que es
 * lo que consume el planificador, y sigue valiendo el día que las tareas dejen de vivir en una
 * constante.
 */
final class CronManifestTest extends KernelTestCase
{
    /**
     * Cada tarea tiene etiqueta legible. Sin ella, los mensajes del gate y el registro dirían
     * «cron.guardia_raices_reminders está desactivada», que no se lo lee nadie.
     */
    public function testCadaTareaTieneEtiquetaLegible(): void
    {
        self::bootKernel();
        $manifest = self::getContainer()->get(CentreCronManifest::class);

        foreach (array_keys(CentreCronManifest::TASKS) as $key) {
            self::assertNotSame(
                $key,
                $manifest->label($key),
                \sprintf('La tarea "%s" no tiene etiqueta: se mostraría su clave técnica.', $key)
            );
        }
    }

    /**
     * Las claves referenciadas en `requires` y `depends_on` existen. Una referencia rota haría que el
     * gate consultara un interruptor inexistente y —con el valor por defecto ENCENDIDO de este
     * proyecto— la tarea pasaría el gate creyendo que un ajuste que no existe está puesto.
     */
    public function testLasReferenciasDelManifiestoExisten(): void
    {
        self::bootKernel();
        $tasks = $this->tasks();

        foreach ($tasks as $key => $meta) {
            self::assertArrayHasKey('requires', $meta, \sprintf('La tarea "%s" no declara `requires`.', $key));
            self::assertArrayHasKey('depends_on', $meta, \sprintf('La tarea "%s" no declara `depends_on`.', $key));

            $dependencies = $meta['depends_on'];
            self::assertIsArray($dependencies);
            foreach ($dependencies as $dependency) {
                self::assertIsString($dependency);
                self::assertArrayHasKey(
                    $dependency,
                    $tasks,
                    \sprintf('La tarea "%s" depende de la tarea inexistente "%s".', $key, $dependency)
                );
            }
        }
    }

    /**
     * La cadencia está bien formada: frecuencia conocida y el campo que corresponda a esa frecuencia
     * (hora en las de calendario, minutos en las de intervalo, día de la semana en las semanales, día
     * del mes en las mensuales).
     */
    public function testLaCadenciaEstaBienFormada(): void
    {
        self::bootKernel();

        foreach ($this->tasks() as $key => $meta) {
            self::assertArrayHasKey('schedule', $meta, \sprintf('La tarea "%s" no declara cadencia.', $key));
            $schedule = $meta['schedule'];
            self::assertIsArray($schedule);
            self::assertArrayHasKey('freq', $schedule, \sprintf('La cadencia de "%s" no dice de qué tipo es.', $key));

            self::assertContains($schedule['freq'], ['daily', 'weekly', 'monthly', 'interval'], \sprintf('Frecuencia desconocida en "%s".', $key));

            if ('interval' === $schedule['freq']) {
                self::assertArrayHasKey('minutes', $schedule, \sprintf('La tarea por intervalo "%s" no dice cada cuántos minutos.', $key));
                self::assertGreaterThanOrEqual(1, $schedule['minutes'], \sprintf('Un intervalo de 0 minutos en "%s" haría correr la tarea en cada tick.', $key));
                continue;
            }

            self::assertArrayHasKey('hour', $schedule, \sprintf('La tarea de calendario "%s" no dice a qué hora.', $key));
            self::assertGreaterThanOrEqual(0, $schedule['hour'], \sprintf('Hora fuera de rango en "%s".', $key));
            self::assertLessThanOrEqual(23, $schedule['hour'], \sprintf('Hora fuera de rango en "%s".', $key));

            if ('weekly' === $schedule['freq']) {
                self::assertArrayHasKey('dow', $schedule, \sprintf('La tarea semanal "%s" no dice qué día.', $key));
                self::assertGreaterThanOrEqual(1, $schedule['dow']);
                self::assertLessThanOrEqual(7, $schedule['dow']);
            }
            if ('monthly' === $schedule['freq']) {
                self::assertArrayHasKey('dom', $schedule, \sprintf('La tarea mensual "%s" no dice qué día del mes.', $key));
                self::assertGreaterThanOrEqual(1, $schedule['dom']);
                self::assertLessThanOrEqual(28, $schedule['dom'], 'Un día > 28 no existe todos los meses.');
            }
        }
    }

    /**
     * El plazo máximo de retraso da margen sobre la cadencia. Un plazo más corto que el propio período
     * entre ejecuciones marcaría como caída una tarea perfectamente sana, y a la tercera falsa alarma
     * nadie vuelve a mirar el aviso — que es el mecanismo original de la avería.
     *
     * Las de intervalo se miden contra el RELOJ DE RESPALDO y no contra su propia cadencia, y ésa es la
     * particularidad de este proyecto: con el reloj de cinco minutos muerto, GitHub Actions sigue
     * llamando cada hora y esas tareas siguen corriendo cada hora. Eso es sano, así que un plazo de una
     * hora daría rojo permanente durante toda la caída del reloj principal — el aviso correcto, dado a
     * la hora equivocada, y por el motivo equivocado.
     */
    public function testElPlazoDeRetrasoDaMargenSobreLaCadencia(): void
    {
        /** Cada cuántas horas pasa el reloj de respaldo (.github/workflows/cron-tick.yml). */
        $backupClockHours = 1;
        $minimumByFreq = ['daily' => 24, 'weekly' => 168, 'monthly' => 744];

        self::bootKernel();

        foreach ($this->tasks() as $key => $meta) {
            $schedule = $meta['schedule'];
            self::assertIsArray($schedule);
            $freq = $schedule['freq'];
            self::assertIsString($freq);
            $minimum = 'interval' === $freq ? $backupClockHours : ($minimumByFreq[$freq] ?? null);
            self::assertNotNull($minimum, \sprintf('Frecuencia "%s" sin período conocido en "%s".', $freq, $key));

            self::assertGreaterThan(
                $minimum,
                $meta['max_delay_hours'],
                \sprintf('El plazo de "%s" no da margen sobre el período real entre ejecuciones: daría falsas alarmas.', $key)
            );
        }
    }

    /**
     * Todos los comandos del manifiesto existen, heredan de la base (que es quien aplica el gate,
     * toma el cerrojo y registra) y aceptan las opciones que el manifiesto dice que aceptan.
     *
     * `--force` se exige a TODAS: es lo que lanza el runner en modo forzado, y una tarea que no la
     * declare abortaría con una excepción de consola en cuanto alguien intentara lanzarla a mano.
     * `--dry-run` solo a las que declaren `dry`, porque ninguno de los barridos de este centro sabe
     * previsualizar y declararlo como que sí sería una mentira que reventaría al pulsar el botón.
     */
    public function testLosComandosExistenHeredanDeLaBaseYAceptanElContrato(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);

        foreach ($this->tasks() as $key => $meta) {
            self::assertArrayHasKey('command', $meta, \sprintf('La tarea "%s" no dice qué comando ejecuta.', $key));
            $name = $meta['command'];
            self::assertIsString($name);
            $command = $this->unwrap($application->find($name));

            self::assertInstanceOf(
                AbstractCronCommand::class,
                $command,
                \sprintf('El comando de "%s" no hereda de AbstractCronCommand: se saltaría el gate, el cerrojo y el registro.', $key)
            );

            $definition = $command->getDefinition();
            self::assertTrue($definition->hasOption('force'), \sprintf('El comando de "%s" no acepta --force.', $key));

            self::assertSame(
                $meta['dry'],
                $definition->hasOption('dry-run'),
                \sprintf('Lo que "%s" declara sobre --dry-run no es lo que su comando acepta.', $key)
            );

            if (true === ($meta['needs_recipient'] ?? false)) {
                self::assertTrue($definition->hasOption('to'), \sprintf('El comando de "%s" dice necesitar destinatario pero no acepta --to.', $key));
            }
        }
    }

    /**
     * La relación inversa: ningún comando que herede de la base se queda fuera del manifiesto. Uno
     * fuera sería una tarea sin cadencia declarada, sin plazo y sin vigilancia; y además reventaría al
     * ejecutarse, porque la base busca su propia entrada y no la encontraría.
     */
    public function testNingunComandoDeCronSeQuedaFueraDelManifiesto(): void
    {
        self::bootKernel();
        $tasks = $this->tasks();
        $declared = array_column($tasks, 'command');
        $checked = 0;

        // Por reflexión y no preguntando a la aplicación: `all()` devuelve los comandos envueltos en
        // LazyCommand, y desenvolverlos instanciaría de golpe todos los comandos del proyecto solo para
        // mirar de qué heredan.
        foreach (glob(\dirname(__DIR__, 2).'/src/Command/*.php') as $file) {
            $class = 'App\\Command\\'.basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract() || !$reflection->isSubclassOf(AbstractCronCommand::class)) {
                continue;
            }

            $attribute = $reflection->getAttributes(AsCommand::class)[0] ?? null;
            self::assertNotNull($attribute, \sprintf('%s no declara #[AsCommand].', $class));

            ++$checked;
            self::assertContains(
                $attribute->newInstance()->name,
                $declared,
                \sprintf('%s hereda de AbstractCronCommand pero no está en CentreCronManifest::TASKS.', $class)
            );
        }

        self::assertSame(\count($tasks), $checked, 'El número de comandos de cron y de entradas del manifiesto debe coincidir.');
    }

    /**
     * Las tareas nacen ENCENDIDAS, sin que haga falta escribir ninguna fila. Es la asimetría deliberada
     * de este proyecto: estos barridos SON los avisos, y una tarea apagada por omisión reproduciría la
     * avería que el planificador viene a cerrar.
     */
    public function testLasTareasNacenEncendidas(): void
    {
        self::bootKernel();
        $registry = self::getContainer()->get(CronTaskRegistry::class);

        foreach (array_keys(CentreCronManifest::TASKS) as $key) {
            self::assertTrue($registry->isEnabled($key), \sprintf('La tarea "%s" nace apagada: no avisaría a nadie y nadie lo sabría.', $key));
            self::assertNull($registry->inhibitedReason($key), \sprintf('La tarea "%s" está inhibida recién instalada.', $key));
        }
    }

    /**
     * La cadencia se describe en castellano legible, que es lo que `/cron/health` publica junto a cada
     * tarea. Se comprueban las dos que existen de verdad aquí, no una genérica.
     */
    public function testLaCadenciaSeDescribeEnCastellano(): void
    {
        self::bootKernel();
        $registry = self::getContainer()->get(CronTaskRegistry::class);

        self::assertSame('a diario a las 07:00', $registry->describeSchedule(CentreCronManifest::CRON_TASK_REMINDERS));
        self::assertSame('cada 5 minutos', $registry->describeSchedule(CentreCronManifest::CRON_MEETING_REMINDERS));
        self::assertSame('a diario a las 04:00', $registry->describeSchedule(CentreCronManifest::CRON_PURGE_LOG));
    }

    /**
     * La zona horaria del manifiesto es la del centro, y sale de {@see \App\Util\AppTime} en vez de
     * estar escrita otra vez: dos sitios que digan en qué zona vive la aplicación son dos sitios que
     * pueden discrepar, y esa discrepancia mueve las horas de los avisos sin que nada falle.
     */
    public function testLaZonaHorariaEsLaDelCentroYNoUnaCopia(): void
    {
        self::bootKernel();

        self::assertSame(
            \App\Util\AppTime::ZONE,
            self::getContainer()->get(CentreCronManifest::class)->timezone()
        );
    }

    /**
     * La poda va ÚLTIMA en el manifiesto, y el orden importa porque el tick ejecuta las tareas en el
     * orden declarado: puesta antes, borraría en la misma pasada el registro de lo que está a punto de
     * ejecutarse.
     */
    public function testLaPodaVaLaUltima(): void
    {
        $keys = array_keys(CentreCronManifest::TASKS);

        self::assertSame(CentreCronManifest::CRON_PURGE_LOG, end($keys));
    }

    /**
     * Las tareas tal y como las SIRVE el manifiesto (ver la cabecera de la clase).
     *
     * @return array<string, array<string, mixed>> clave de tarea => metadatos
     */
    private function tasks(): array
    {
        return self::getContainer()->get(CentreCronManifest::class)->tasks();
    }

    /**
     * El comando real detrás de un LazyCommand.
     *
     * Symfony registra envueltos en LazyCommand los comandos que declaran descripción en #[AsCommand]
     * (AddConsoleCommandPass), para no instanciarlos al arrancar. Sin desenvolver, cualquier
     * comprobación de tipo sobre la clase del comando miente.
     *
     * @param Command $command comando tal y como lo devuelve la aplicación
     *
     * @return Command el comando de verdad
     */
    private function unwrap(Command $command): Command
    {
        return $command instanceof LazyCommand ? $command->getCommand() : $command;
    }
}
