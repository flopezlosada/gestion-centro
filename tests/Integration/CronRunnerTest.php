<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\CronRun;
use App\Repository\CronRunRepository;
use App\Service\AppSettings;
use App\Service\Cron\Adapter\CentreCronManifest;
use App\Service\Cron\CronRunMode;
use App\Service\Cron\CronRunner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * El runner: la única puerta por la que se lanza una tarea programada, sea quien sea quien la pida.
 *
 * Lo que se vigila aquí son sus dos ejes, que son INDEPENDIENTES y se confunden con facilidad:
 *
 * - El MODO (forzar, ejecutar como el reloj, previsualizar): qué se salta y qué no.
 * - El ORIGEN del disparo (crontab, tick, persona): quién lo pidió.
 *
 * Deducir el segundo del primero es el error clásico —«si trae --force lo pidió alguien»— y su
 * consecuencia es que el registro dé por vivo un reloj parado. Se prueba con la poda, que es la única
 * tarea inocua: no avisa a nadie.
 */
final class CronRunnerTest extends KernelTestCase
{
    private CronRunner $runner;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->runner = self::getContainer()->get(CronRunner::class);
    }

    /**
     * Una clave que no está en el manifiesto no se ejecuta. El manifiesto ES la lista blanca: si no lo
     * fuera, el runner sería una consola remota con nombres de comando por parámetro.
     */
    public function testUnaTareaDesconocidaNoSeEjecuta(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->runner->run('cron.no_existe', CronRunMode::AsScheduled);
    }

    /**
     * Forzar salta el interruptor PROPIO de la tarea: es el puente manual cuando el reloj está caído.
     */
    public function testForzarSaltaElInterruptorDeLaTarea(): void
    {
        $this->pause(CentreCronManifest::CRON_PURGE_LOG);

        $forzada = $this->runner->run(CentreCronManifest::CRON_PURGE_LOG, CronRunMode::Forced);

        self::assertTrue($forzada->isSuccessful());
        self::assertNotSame(
            CronRun::STATUS_DISABLED,
            $this->lastRun(CentreCronManifest::CRON_PURGE_LOG)?->getStatus(),
            'Con --force la tarea pausada tiene que ejecutarse de verdad.'
        );
    }

    /**
     * Y sin forzar NO la salta: la tarea pausada se registra como «apagada por configuración», que es un
     * estado sano y distinto de haber trabajado. Es lo que hace que pausar una tarea sea visible en vez
     * de parecer que el reloj se murió.
     */
    public function testSinForzarLaTareaPausadaNoSeEjecutaYQuedaConstancia(): void
    {
        $this->pause(CentreCronManifest::CRON_PURGE_LOG);

        $this->runner->run(CentreCronManifest::CRON_PURGE_LOG, CronRunMode::AsScheduled);

        self::assertSame(CronRun::STATUS_DISABLED, $this->lastRun(CentreCronManifest::CRON_PURGE_LOG)?->getStatus());
    }

    /**
     * El ORIGEN se declara, no se deduce del modo. Aquí se lanza SIN forzar y declarándose manual: si el
     * origen se dedujera de `--force`, esta ejecución se registraría como del reloj y el registro daría
     * por vivo un planificador parado.
     */
    public function testElOrigenSeDeclaraYNoSeDeduceDelModo(): void
    {
        $this->runner->run(CentreCronManifest::CRON_PURGE_LOG, CronRunMode::AsScheduled, trigger: CronRun::TRIGGER_MANUAL);

        self::assertSame(CronRun::TRIGGER_MANUAL, $this->lastRun(CentreCronManifest::CRON_PURGE_LOG)?->getTriggerSource());
    }

    /**
     * Y al contrario: forzando y declarándose el crontab, se registra como el crontab. Los dos ejes no se
     * tocan.
     */
    public function testForzarNoConvierteLaEjecucionEnManual(): void
    {
        $this->runner->run(CentreCronManifest::CRON_PURGE_LOG, CronRunMode::Forced, trigger: CronRun::TRIGGER_SCHEDULE);

        self::assertSame(CronRun::TRIGGER_SCHEDULE, $this->lastRun(CentreCronManifest::CRON_PURGE_LOG)?->getTriggerSource());
    }

    /**
     * Previsualizar una tarea que no sabe previsualizar se explica, no revienta.
     *
     * Ninguno de los barridos de este centro tiene modo «cuéntame qué harías», así que pasarle
     * `--dry-run` abortaría con una excepción de consola que en el registro se leería como «el comando
     * lanzó una excepción» — un fallo donde solo había una opción que no existe. Y no se registra nada:
     * una previsualización no es una ejecución y no debe falsear la última.
     */
    public function testPrevisualizarLoQueNoSabePrevisualizarSeExplicaYNoSeRegistra(): void
    {
        $preview = $this->runner->run(CentreCronManifest::CRON_PURGE_LOG, CronRunMode::Preview);

        self::assertFalse($preview->isSuccessful());
        self::assertStringContainsString('no sabe previsualizar', (string) $preview->blocked);
        self::assertNull($this->lastRun(CentreCronManifest::CRON_PURGE_LOG), 'Una previsualización no cuenta como ejecución.');
    }

    /**
     * Reenviar una tarea que no produce efectos repetibles, igual: se explica y no se ejecuta.
     */
    public function testReenviarLoQueNoTieneEfectosRepetiblesSeExplica(): void
    {
        $resend = $this->runner->run(CentreCronManifest::CRON_PURGE_LOG, CronRunMode::Resend);

        self::assertFalse($resend->isSuccessful());
        self::assertStringContainsString('nada que reenviar', (string) $resend->blocked);
    }

    /**
     * Pausa una tarea.
     *
     * @param string $taskKey clave de la tarea
     */
    private function pause(string $taskKey): void
    {
        self::getContainer()->get(AppSettings::class)->setCronTaskEnabled($taskKey, false);
    }

    /**
     * Última ejecución registrada de una tarea, o null si no hay.
     *
     * @param string $taskKey clave de la tarea
     *
     * @return CronRun|null la ejecución más reciente
     */
    private function lastRun(string $taskKey): ?CronRun
    {
        // El registro se escribe por DBAL, así que el EntityManager puede tener en su identity map la
        // ausencia de estas filas.
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(CronRunRepository::class)->findRecentForTask($taskKey, 1)[0] ?? null;
    }
}
