<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NonLectiveDay;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NonLectiveDay>
 */
class NonLectiveDayRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NonLectiveDay::class);
    }

    /**
     * Every registered non-teaching day, earliest first. Used by the admin list.
     *
     * @return NonLectiveDay[] the non-teaching days ordered by date
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('n')
            ->orderBy('n.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The non-teaching days whose date falls within an inclusive range. Used by the monthly calendar
     * to mark the visible grid in a single query (no per-day lookup).
     *
     * @param \DateTimeImmutable $from the first day of the range (inclusive)
     * @param \DateTimeImmutable $to   the last day of the range (inclusive)
     *
     * @return NonLectiveDay[] the non-teaching days within the range
     */
    public function findBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.date BETWEEN :from AND :to')
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->orderBy('n.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Whether a given date is registered as a non-teaching day. Used to validate a single deadline.
     *
     * @param \DateTimeImmutable $date the date to check
     *
     * @return bool true if that exact day is a registered non-teaching day
     */
    public function existsOn(\DateTimeImmutable $date): bool
    {
        return null !== $this->createQueryBuilder('n')
            ->select('n.id')
            ->andWhere('n.date = :date')
            ->setParameter('date', $date->format('Y-m-d'))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
