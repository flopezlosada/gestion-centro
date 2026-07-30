<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\SpacePlan;
use App\Enum\SpacePlanStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SpacePlan>
 */
class SpacePlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpacePlan::class);
    }

    /**
     * A course's plans, the ones starting soonest first — the listing screen. Cancelled ones go last:
     * they are kept for the trail, not to be worked on.
     *
     * @param AcademicYear $year the course
     *
     * @return SpacePlan[] the plans
     */
    public function findForYear(AcademicYear $year): array
    {
        $plans = $this->createQueryBuilder('p')
            ->andWhere('p.academicYear = :year')
            ->setParameter('year', $year)
            ->orderBy('p.dateFrom', 'DESC')
            ->getQuery()
            ->getResult();

        usort($plans, static fn (SpacePlan $a, SpacePlan $b): int => [SpacePlanStatus::CANCELLED === $a->getStatus(), $b->getDateFrom()]
            <=> [SpacePlanStatus::CANCELLED === $b->getStatus(), $a->getDateFrom()]);

        return $plans;
    }

    /**
     * The approved plans that cover a given day — what the effective timetable has to take into account
     * before answering anything about it.
     *
     * @param \DateTimeImmutable $date the day
     *
     * @return SpacePlan[] the approved plans covering it
     */
    public function approvedCovering(\DateTimeImmutable $date): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.status = :approved')
            ->andWhere(':date BETWEEN p.dateFrom AND p.dateTo')
            ->setParameter('approved', SpacePlanStatus::APPROVED)
            ->setParameter('date', $date)
            ->orderBy('p.dateFrom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The approved plans overlapping a date range, for the screens that show a week or a month at once.
     *
     * @param \DateTimeImmutable $from first day, inclusive
     * @param \DateTimeImmutable $to   last day, inclusive
     *
     * @return SpacePlan[] the approved plans that touch the range
     */
    public function approvedBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.status = :approved')
            ->andWhere('p.dateFrom <= :to AND p.dateTo >= :from')
            ->setParameter('approved', SpacePlanStatus::APPROVED)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('p.dateFrom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
