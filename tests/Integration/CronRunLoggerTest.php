<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\CronRun;
use App\Repository\CronRunRepository;
use App\Service\Cron\CronRunLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * El escritor del registro de ejecuciones.
 *
 * Escribe por DBAL con la tabla y las columnas literales, y eso es deliberado por dos razones: un
 * `flush()` del EntityManager arrastraría la unidad de trabajo del comando (persistiendo a medias
 * trabajo que quizá no quería confirmarse), y si el comando muere con una excepción de Doctrine el
 * EntityManager queda cerrado y un `flush()` reventaría — justo en el caso que más importa registrar.
 *
 * El precio de esos literales es que podrían quedar desfasados respecto al mapeo de la entidad sin que
 * nada cantase: el registro dejaría de escribirse en silencio y volveríamos al punto de partida. Aquí
 * se vigilan uno a uno.
 */
final class CronRunLoggerTest extends KernelTestCase
{
    /**
     * La tabla y las columnas que usa el SQL literal son las del mapeo de la entidad. Si alguien
     * renombra una columna en los atributos, este test cae antes de que el registro empiece a fallar en
     * silencio en producción.
     */
    public function testElSqlLiteralCoincideConElMapeoDeLaEntidad(): void
    {
        self::bootKernel();
        $metadata = self::getContainer()->get(EntityManagerInterface::class)->getClassMetadata(CronRun::class);

        self::assertSame('cron_run', $metadata->getTableName());

        $expectedColumns = [
            'taskKey' => 'task_key',
            'command' => 'command',
            'status' => 'status',
            'triggerSource' => 'trigger_source',
            'startedAt' => 'started_at',
            'finishedAt' => 'finished_at',
            'exitCode' => 'exit_code',
            'detail' => 'detail',
            'output' => 'output',
        ];
        foreach ($expectedColumns as $field => $column) {
            self::assertSame($column, $metadata->getColumnName($field), \sprintf('La columna de "%s" ha cambiado de nombre.', $field));
        }
    }

    /**
     * Una ejecución se abre con estado `failed` y sin cierre: así, un proceso que muere a mitad (el
     * `request_terminate_timeout` de php-fpm, que PHP no puede pisar) deja constancia del fallo en lugar
     * de no dejar rastro.
     */
    public function testLaEjecucionNaceComoFalloSinCerrar(): void
    {
        self::bootKernel();
        $logger = self::getContainer()->get(CronRunLogger::class);

        $runId = $logger->start('cron.test_logger', 'app:test-logger', CronRun::TRIGGER_SCHEDULE);
        self::assertNotNull($runId);

        $run = self::getContainer()->get(CronRunRepository::class)->find($runId);
        self::assertNotNull($run);
        self::assertSame(CronRun::STATUS_FAILED, $run->getStatus());
        self::assertFalse($run->isFinished(), 'Una ejecución recién abierta no tiene cierre.');
    }

    /**
     * Al cerrar se guardan estado, código de salida, resumen y salida.
     */
    public function testCerrarGuardaElResultado(): void
    {
        self::bootKernel();
        $logger = self::getContainer()->get(CronRunLogger::class);

        $runId = $logger->start('cron.test_logger', 'app:test-logger', CronRun::TRIGGER_TICK);
        $logger->finish($runId, CronRun::STATUS_DONE, 0, '3 avisos enviados', "linea 1\nlinea 2");

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $run = self::getContainer()->get(CronRunRepository::class)->find($runId);

        self::assertNotNull($run);
        self::assertSame(CronRun::STATUS_DONE, $run->getStatus());
        self::assertSame(0, $run->getExitCode());
        self::assertSame('3 avisos enviados', $run->getDetail());
        self::assertStringContainsString('linea 2', (string) $run->getOutput());
        self::assertTrue($run->isFinished());
        self::assertSame(CronRun::TRIGGER_TICK, $run->getTriggerSource(), 'El origen del disparo se guarda tal cual se declaró.');
    }

    /**
     * Cerrar un id nulo no hace nada y no revienta. Es el camino que se toma cuando la apertura falló
     * (por ejemplo porque la tabla no existe todavía en ese entorno): la observabilidad no puede tumbar
     * la tarea que observa.
     */
    public function testCerrarSinIdEsInocuo(): void
    {
        self::bootKernel();

        self::getContainer()->get(CronRunLogger::class)->finish(null, CronRun::STATUS_DONE, 0, 'nada');

        self::assertSame(0, self::getContainer()->get(CronRunRepository::class)->count([]));
    }
}
