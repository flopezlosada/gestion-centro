<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CronRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CronRun>
 */
class CronRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CronRun::class);
    }

    /**
     * Última ejecución de CADA tarea, en una sola query: el tick la llama una vez por pasada y el
     * chequeo de salud una vez por petición, así que un findOneBy por tarea sería un N+1.
     *
     * La subconsulta agrupa por tarea y se queda con el id más alto, que es también el más reciente
     * por ser autoincremental; así se evita el `MAX(started_at)` con empate al segundo, y el GROUP BY
     * solo lleva la columna agrupada (compatible con ONLY_FULL_GROUP_BY).
     *
     * @return array<string, CronRun> clave de tarea => su última ejecución
     */
    public function findLastRunPerTask(): array
    {
        $runs = $this->getEntityManager()
            ->createQuery(
                'SELECT r FROM '.CronRun::class.' r
                 WHERE r.id IN (
                     SELECT MAX(r2.id) FROM '.CronRun::class.' r2 GROUP BY r2.taskKey
                 )'
            )
            ->getResult();

        $byTask = [];
        foreach ($runs as $run) {
            $byTask[$run->getTaskKey()] = $run;
        }

        return $byTask;
    }

    /**
     * Historial reciente de una tarea, de más nueva a más vieja.
     *
     * @param string $taskKey clave de la tarea en el manifiesto
     * @param int    $limit   cuántas ejecuciones devolver
     *
     * @return CronRun[] las ejecuciones, la más reciente primero
     */
    public function findRecentForTask(string $taskKey, int $limit = 20): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.taskKey = :task')
            ->setParameter('task', $taskKey)
            ->orderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Borra el registro más viejo que la fecha de corte, PRESERVANDO la última ejecución de cada
     * tarea.
     *
     * Preservarla no es un detalle: el chequeo de salud mide el retraso contra ella, y sin ninguna
     * ejecución registrada NO considera que haya retraso (no hay desde dónde medir). Una purga ciega
     * borraría la última fila de una tarea que lleva meses parada y devolvería el chequeo a verde —
     * o sea, el silencio otra vez, que es justo lo que este registro existe para romper.
     *
     * Los ids a conservar se resuelven en una consulta aparte y viajan como lista, en vez de en una
     * subconsulta: MySQL/MariaDB no admiten subconsultar la misma tabla que se está borrando, y son
     * tantos ids como tareas hay (media docena).
     *
     * ⚠️ Y se piden como ESCALARES, no con {@see self::findLastRunPerTask()}, aunque la consulta sea la
     * misma. Hidratar entidades aquí tenía una consecuencia que costó un CI en rojo entenderla: quien
     * llama es la propia tarea de poda, y en ese momento SU fila del registro está todavía en su estado
     * de nacimiento (`failed`, para que un proceso que muera a mitad deje constancia). Esa entidad se
     * quedaba en el identity map con `failed`, y el cierre de la ejecución la corrige por DBAL, que no
     * pasa por el EntityManager. Al leer el registro otra vez en el mismo proceso, Doctrine devolvía la
     * copia rancia —que sigue diciendo `failed`—, el evaluador lo tomaba por un intento fallido y
     * REINTENTABA la tarea en la pasada siguiente. En producción cada tick es una petición nueva y no se
     * notaba; en un proceso que ejecute dos ticks, sí.
     *
     * @param \DateTimeImmutable $cutoff todo lo arrancado ANTES de este instante se borra
     *
     * @return int cuántas filas se han borrado
     */
    public function purgeOlderThan(\DateTimeImmutable $cutoff): int
    {
        $keep = array_map(
            static fn (array $row): int => (int) $row['lastId'],
            $this->getEntityManager()
                ->createQuery('SELECT MAX(r.id) AS lastId FROM '.CronRun::class.' r GROUP BY r.taskKey')
                ->getScalarResult()
        );

        $query = $this->createQueryBuilder('r')
            ->delete()
            ->where('r.startedAt < :cutoff')
            ->setParameter('cutoff', $cutoff);

        if ([] !== $keep) {
            $query->andWhere('r.id NOT IN (:keep)')->setParameter('keep', $keep);
        }

        return (int) $query->getQuery()->execute();
    }
}
