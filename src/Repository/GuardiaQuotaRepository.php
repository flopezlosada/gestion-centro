<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\GuardiaQuota;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GuardiaQuota>
 */
class GuardiaQuotaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GuardiaQuota::class);
    }

    /**
     * The course's quotas keyed by teacher id, which is how every caller wants them: the screen draws a
     * row per teacher and needs to find that teacher's quota, and a teacher with no row yet simply has
     * no key.
     *
     * The teacher is joined in the same read. Without it, rendering a table of seventy rows would fire
     * seventy extra queries to print the names.
     *
     * @param AcademicYear $year the course whose quotas to read
     *
     * @return array<int, GuardiaQuota> the quotas, keyed by teacher id
     */
    public function findByYearKeyedByTeacher(AcademicYear $year): array
    {
        /** @var GuardiaQuota[] $rows */
        $rows = $this->createQueryBuilder('q')
            ->addSelect('t')
            ->join('q.teacher', 't')
            ->andWhere('q.academicYear = :year')
            ->setParameter('year', $year)
            ->getQuery()
            ->getResult();

        $byTeacher = [];
        foreach ($rows as $row) {
            $byTeacher[(int) $row->getTeacher()->getId()] = $row;
        }

        return $byTeacher;
    }
}
