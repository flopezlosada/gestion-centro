<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Meeting;
use App\Entity\MeetingRemark;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MeetingRemark>
 */
class MeetingRemarkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MeetingRemark::class);
    }

    /**
     * The observations on a meeting's acta, oldest first — they read in the order they were raised, and a
     * later one often answers an earlier one.
     *
     * The scope check is NOT here: who may read these is who may open the meeting, decided once in
     * {@see \App\Service\MeetingAccess::canSee()} before this is ever called.
     *
     * @param Meeting $meeting the meeting
     *
     * @return list<MeetingRemark> the observations, oldest first
     */
    public function findThreadFor(Meeting $meeting): array
    {
        // Fetch-join the author: every row prints their name (avoids an N+1 over the thread).
        return $this->createQueryBuilder('r')
            ->leftJoin('r.author', 'author')->addSelect('author')
            ->andWhere('r.meeting = :meeting')->setParameter('meeting', $meeting)
            ->orderBy('r.createdAt', 'ASC')->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
