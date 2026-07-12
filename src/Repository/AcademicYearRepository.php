<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AcademicYear>
 */
class AcademicYearRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AcademicYear::class);
    }

    /**
     * Every registered course structure, most recent course first. Used by the admin list.
     *
     * @return AcademicYear[] the course structures ordered by school year, descending
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('a')
            ->orderBy('a.schoolYear', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The structure of a given course, if it has been set.
     *
     * @param string $schoolYear the course in "YYYY-YYYY" form
     *
     * @return AcademicYear|null the course structure, or null if not defined yet
     */
    public function findBySchoolYear(string $schoolYear): ?AcademicYear
    {
        return $this->findOneBy(['schoolYear' => $schoolYear]);
    }
}
