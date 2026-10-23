<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\ArchivedBreakDutyGap;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The archived half of the unwatched recreos. Its counts are read through
 * {@see BreakDutyGapRepository::summary()}, which adds them to the live ones, so that a recreo passing the
 * plazo does not change how many the course had.
 *
 * @extends ServiceEntityRepository<ArchivedBreakDutyGap>
 */
class ArchivedBreakDutyGapRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArchivedBreakDutyGap::class);
    }

    /**
     * The archived recreos of a course, most recent first, with whoever stepped in.
     *
     * @param AcademicYear $year the course
     *
     * @return list<ArchivedBreakDutyGap> the recreos
     */
    public function findByYear(AcademicYear $year): array
    {
        /** @var list<ArchivedBreakDutyGap> $gaps */
        $gaps = $this->createQueryBuilder('g')
            ->addSelect('v')
            ->leftJoin('g.volunteer', 'v')
            ->andWhere('g.academicYear = :year')
            ->setParameter('year', $year)
            ->orderBy('g.date', 'DESC')
            ->getQuery()
            ->getResult();

        return $gaps;
    }

    /**
     * How many archived recreos a course has and how many of those somebody covered.
     *
     * @param AcademicYear            $year the course
     * @param \DateTimeImmutable|null $from window start, inclusive, or null for no lower bound
     * @param \DateTimeImmutable|null $to   window end, inclusive, or null for no upper bound
     *
     * @return array{total: int, covered: int} the counts
     */
    public function summary(AcademicYear $year, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): array
    {
        $qb = $this->createQueryBuilder('g')
            ->select('COUNT(g.id) AS total', 'SUM(CASE WHEN g.volunteer IS NOT NULL THEN 1 ELSE 0 END) AS covered')
            ->andWhere('g.academicYear = :year')
            ->setParameter('year', $year);
        if (null !== $from) {
            $qb->andWhere('g.date >= :from')->setParameter('from', $from);
        }
        if (null !== $to) {
            $qb->andWhere('g.date <= :to')->setParameter('to', $to);
        }

        /** @var array{total: int|string, covered: int|string|null} $row */
        $row = $qb->getQuery()->getSingleResult();

        return ['total' => (int) $row['total'], 'covered' => (int) ($row['covered'] ?? 0)];
    }
}
