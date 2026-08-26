<?php

declare(strict_types=1);

namespace App\Service\Cron;

use App\Entity\CronRun;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Escribe el registro de ejecuciones de tareas programadas
 * ({@see CronRun}): una fila al arrancar y su cierre al terminar.
 *
 * Escribe por DBAL, no por el EntityManager, y es deliberado por dos razones:
 *
 * 1. Un `flush()` del EntityManager arrastraría los cambios pendientes del
 *    propio comando, persistiendo a medias trabajo que el comando quizá no
 *    quería confirmar todavía. El registro es un log técnico y no debe
 *    compartir unidad de trabajo con el negocio.
 * 2. Si el comando muere con una excepción de Doctrine, el EntityManager queda
 *    cerrado y un `flush()` reventaría — justo en el caso que más importa
 *    registrar, el fallo.
 *
 * Ningún error al registrar puede tumbar el comando: la observabilidad no
 * puede romper la tarea que observa. Los fallos se anotan en el log de la app.
 *
 * Los nombres de tabla y columnas van literales para que el SQL se lea; que
 * coincidan con el mapeo de la entidad lo vigila
 * {@see \App\Tests\Service\Cron\CronRunLoggerTest}.
 */
class CronRunLogger
{
    private const TABLE = 'cron_run';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Abre el registro de una ejecución y devuelve su id.
     *
     * Nace con estado `failed` a propósito: si el proceso muere sin llegar a
     * cerrarla (timeout de php-fpm, kill, falta de memoria), queda constancia
     * del fallo en lugar de desaparecer del registro. Una fila `failed` sin
     * `finished_at` significa exactamente "arrancó y no volvió".
     *
     * @param string $taskKey Clave de la tarea en el manifiesto.
     * @param string $command Nombre del comando de consola.
     * @param string $trigger CronRun::TRIGGER_SCHEDULE o TRIGGER_MANUAL.
     * @return int|null Id de la fila creada, o null si no se pudo registrar.
     */
    public function start(string $taskKey, string $command, string $trigger): ?int
    {
        try {
            $connection = $this->connection();
            $connection->insert(self::TABLE, [
                'task_key' => $taskKey,
                'command' => $command,
                'status' => CronRun::STATUS_FAILED,
                'trigger_source' => $trigger,
                'started_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);

            return (int) $connection->lastInsertId();
        } catch (\Throwable $e) {
            $this->logger->error('No se pudo abrir el registro de la tarea {task}: {error}', [
                'task' => $taskKey,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Cierra el registro de una ejecución con su resultado.
     *
     * @param int|null    $runId    Id devuelto por {@see self::start()}; null = no registrar.
     * @param string      $status   Uno de los cuatro CronRun::STATUS_*.
     * @param int         $exitCode Código de salida del comando.
     * @param string|null $detail   Resumen de una línea de lo ocurrido.
     * @param string|null $output   Salida del comando (se recorta al persistir).
     */
    public function finish(?int $runId, string $status, int $exitCode, ?string $detail = null, ?string $output = null): void
    {
        if ($runId === null) {
            return;
        }

        // El recorte de salida y detalle vive en la entidad (una sola regla), así
        // que se reutiliza aunque aquí se escriba por DBAL.
        $shaped = (new CronRun())->setDetail($detail)->setOutput($output);

        try {
            $this->connection()->update(self::TABLE, [
                'status' => $status,
                'finished_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'exit_code' => $exitCode,
                'detail' => $shaped->getDetail(),
                'output' => $shaped->getOutput(),
            ], ['id' => $runId]);
        } catch (\Throwable $e) {
            $this->logger->error('No se pudo cerrar el registro {id} de tarea programada: {error}', [
                'id' => $runId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Conexión DBAL. Se pide al EntityManager en cada llamada porque, si una
     * excepción lo ha cerrado, la conexión sigue siendo válida aunque él no.
     */
    private function connection(): Connection
    {
        return $this->em->getConnection();
    }
}
