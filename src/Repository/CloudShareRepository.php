<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CloudShare;
use App\Entity\Meeting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CloudShare>
 */
class CloudShareRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CloudShare::class);
    }

    /**
     * The shares still to be made, oldest first: whoever was queued first is shared first.
     *
     * @return list<CloudShare>
     */
    public function findPending(): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.problem IS NULL')
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * What is left of one meeting's shares: pending or given up on.
     *
     * @return list<CloudShare>
     */
    public function findForMeeting(Meeting $meeting): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.meeting = :meeting')
            ->setParameter('meeting', $meeting)
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
