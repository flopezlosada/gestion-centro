<?php

declare(strict_types=1);

namespace App\Service\Cron;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Cerrojo de exclusión mutua por tarea: impide que la MISMA tarea se ejecute
 * dos veces a la vez.
 *
 * POR QUÉ HACE FALTA. Hasta ahora el reloj disparaba cada tarea una vez por
 * semana o por día, así que dos ejecuciones simultáneas eran impensables. El
 * planificador propio cambia eso por partida doble: el tick pasa cada hora (o
 * cada pocos minutos en otros proyectos), y el diseño prevé DOS relojes
 * independientes apuntando al mismo tick para no depender de un proveedor. Con
 * eso, dos procesos entrando a la vez en la misma tarea deja de ser hipótesis.
 *
 * Y eso importa porque no todas las tareas son idempotentes por sí solas. Las
 * que producen efectos externos se protegen una a una con {@see EffectLedger},
 * pero las que sólo escriben en la base de datos suelen protegerse con un `if`
 * ("¿ya existe esto? entonces no lo genero"), y un `if` pierde la carrera: dos
 * procesos lo leen a la vez, los dos concluyen que no existe y los dos lo
 * generan. En esta aplicación los cinco barridos de avisos se protegen cada uno
 * con su propio sello en la base de datos, que es el mismo `if`: con dos relojes
 * llamando al mismo tick, el cerrojo es lo que impide que dos pasadas simultáneas
 * lean el sello a la vez y manden el aviso dos veces.
 *
 * El cerrojo es genérico a propósito: no sabe qué hace la tarea que protege ni
 * necesita saberlo. Cualquier tarea futura queda cubierta por heredar de
 * {@see \App\Command\AbstractCronCommand}.
 *
 * IMPLEMENTACIÓN: bloqueos con nombre de MySQL (`GET_LOCK`), no una fila de
 * "estoy corriendo" en una tabla. La razón es la recuperación tras un desastre:
 * el bloqueo de MySQL se libera solo en cuanto la conexión se cierra, sea
 * porque el comando terminó, porque php-fpm lo mató por tiempo o porque el
 * proceso murió de golpe. Una fila en tabla, en cambio, se queda clavada y
 * bloquea la tarea para siempre hasta que alguien la borra a mano — que en un
 * hosting sin SSH significa entrar por phpMyAdmin a arreglarlo.
 */
class TaskLock
{
    /**
     * Tope de MySQL para el nombre de un bloqueo (64 caracteres desde 5.7). Los
     * nombres más largos se resumen en un hash.
     */
    private const MAX_NAME_LENGTH = 64;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Intenta tomar el cerrojo de una tarea SIN esperar. Devuelve false si ya lo
     * tiene otro proceso, que es la señal de "esta tarea ya está corriendo".
     *
     * Si el motor no puede dar una respuesta (por ejemplo porque no soporta
     * bloqueos con nombre), devuelve true y lo anota en el log: preferimos
     * ejecutar sin protección a dejar la tarea sin ejecutar nunca. Mismo
     * criterio que {@see CronRunLogger}: el andamiaje no puede tumbar el
     * trabajo que vigila.
     *
     * @param string $key Clave de la tarea en el manifiesto.
     */
    public function acquire(string $key): bool
    {
        try {
            $granted = $this->connection()->fetchOne(
                'SELECT GET_LOCK(?, 0)',
                [$this->lockName($key)]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('No se pudo tomar el cerrojo de la tarea {task}, se ejecuta sin protección: {error}', [
                'task' => $key,
                'error' => $e->getMessage(),
            ]);

            return true;
        }

        // GET_LOCK devuelve 1 (concedido), 0 (lo tiene otro) o NULL (error del
        // motor). El NULL se trata como el fallo de arriba: no bloquear.
        if ($granted === null) {
            $this->logger->warning('El motor no resolvió el cerrojo de la tarea {task}, se ejecuta sin protección.', [
                'task' => $key,
            ]);

            return true;
        }

        return (int) $granted === 1;
    }

    /**
     * Suelta el cerrojo de una tarea. Es seguro llamarlo aunque no se tuviera.
     *
     * @param string $key Clave de la tarea en el manifiesto.
     */
    public function release(string $key): void
    {
        try {
            $this->connection()->fetchOne('SELECT RELEASE_LOCK(?)', [$this->lockName($key)]);
        } catch (\Throwable $e) {
            // No hay nada que hacer y no debe propagarse: el cerrojo caerá solo
            // cuando se cierre la conexión.
            $this->logger->warning('No se pudo soltar el cerrojo de la tarea {task}: {error}', [
                'task' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Nombre del bloqueo. Va prefijado con el nombre de la base de datos porque
     * los bloqueos de MySQL son GLOBALES AL SERVIDOR, no de la base de datos:
     * en un hosting compartido, sin prefijo, dos aplicaciones distintas con la
     * misma clave de tarea se bloquearían entre ellas. Si el nombre se pasa del
     * tope de MySQL se resume en un hash, que sigue siendo estable y único.
     *
     * @param string $key Clave de la tarea en el manifiesto.
     */
    private function lockName(string $key): string
    {
        $name = sprintf('%s:%s', $this->connection()->getDatabase() ?? 'app', $key);

        return mb_strlen($name) <= self::MAX_NAME_LENGTH ? $name : 'cron:' . sha1($name);
    }

    /**
     * Conexión DBAL. Se pide al EntityManager en cada llamada porque, si una
     * excepción lo ha cerrado, la conexión sigue siendo válida aunque él no —
     * y soltar el cerrojo hay que poder hacerlo justo en ese caso.
     */
    private function connection(): Connection
    {
        return $this->em->getConnection();
    }
}
