<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FamilyActivity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FamilyActivity>
 */
class FamilyActivityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FamilyActivity::class);
    }

    /**
     * The activities still to come (or happening today), soonest first — what is being planned.
     *
     * @param \DateTimeImmutable $today the current day; anything starting on it or later is listed
     *
     * @return FamilyActivity[] the activities
     */
    public function findUpcoming(\DateTimeImmutable $today): array
    {
        return $this->listQuery()
            ->andWhere('a.startAt >= :from')->setParameter('from', $today->setTime(0, 0))
            ->orderBy('a.startAt', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The activities already held, most recent first — the record of what was done.
     *
     * @param \DateTimeImmutable $today the current day; anything starting before it is listed
     *
     * @return FamilyActivity[] the activities
     */
    public function findPast(\DateTimeImmutable $today): array
    {
        return $this->listQuery()
            ->andWhere('a.startAt < :from')->setParameter('from', $today->setTime(0, 0))
            ->orderBy('a.startAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The base of both lists. The organiser and the families coming are printed on every row:
     * fetch-joined to avoid an N+1.
     *
     * @return QueryBuilder the query, without filter or order
     */
    private function listQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.organizer', 'organizer')->addSelect('organizer')
            ->leftJoin('a.signups', 'signup')->addSelect('signup')
            ->leftJoin('signup.family', 'family')->addSelect('family');
    }
}
