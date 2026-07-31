<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TicIncident;
use App\Enum\IncidentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TicIncident>
 */
class TicIncidentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TicIncident::class);
    }

    /**
     * The incidents to show, newest and most urgent first.
     *
     * Ordered by the materialised weight and not by the priority string: "high" sorts after "low"
     * alphabetically, which is exactly backwards ({@see \App\Enum\IncidentPriority::weight()}).
     *
     * @param bool $onlyOpen true for what is still to be dealt with (the default view), false for all
     *
     * @return TicIncident[] the incidents
     */
    public function findForList(bool $onlyOpen = true): array
    {
        $qb = $this->createQueryBuilder('i')
            // Both are printed on every row: fetch-joined to avoid an N+1 over the list.
            ->leftJoin('i.room', 'room')->addSelect('room')
            ->leftJoin('i.reportedBy', 'reporter')->addSelect('reporter')
            ->orderBy('i.priorityWeight', 'ASC')
            ->addOrderBy('i.occurredAt', 'DESC');

        if ($onlyOpen) {
            $qb->andWhere('i.status <> :resolved')->setParameter('resolved', IncidentStatus::RESOLVED);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * How many incidents are still open, for the badge on the module row.
     *
     * @return int the open count
     */
    public function countOpen(): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.status <> :resolved')->setParameter('resolved', IncidentStatus::RESOLVED)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
