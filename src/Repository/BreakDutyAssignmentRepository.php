<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\BreakDutyAssignment;
use App\Entity\BreakZone;
use App\Entity\User;
use App\Enum\Weekday;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BreakDutyAssignment>
 */
class BreakDutyAssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BreakDutyAssignment::class);
    }

    /**
     * The whole rota of a course, ready to lay out as a weekday × zone grid: teachers and zones are
     * eager-loaded, since the grid reads a name from every row (one query, not one per cell).
     *
     * @param AcademicYear $year the course whose rota to read
     *
     * @return BreakDutyAssignment[] every duty of the course, by weekday then zone then teacher
     */
    public function findByYear(AcademicYear $year): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('t', 'z')
            ->join('a.teacher', 't')
            ->join('a.zone', 'z')
            ->andWhere('a.academicYear = :year')
            ->setParameter('year', $year)
            ->orderBy('a.weekday', 'ASC')
            ->addOrderBy('z.sortOrder', 'ASC')
            ->addOrderBy('t.fullName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * A teacher's own duties in a course — the "mi guardia de recreo" line on their screens. Usually one.
     *
     * @param AcademicYear $year    the course whose rota to read
     * @param User         $teacher the teacher
     *
     * @return BreakDutyAssignment[] their duties, weekday ascending, zone joined
     */
    public function findByTeacher(AcademicYear $year, User $teacher): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('z')
            ->join('a.zone', 'z')
            ->andWhere('a.academicYear = :year')
            ->andWhere('a.teacher = :teacher')
            ->setParameter('year', $year)
            ->setParameter('teacher', $teacher)
            ->orderBy('a.weekday', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The duties on one weekday of a course, zones and teachers joined — what a given day's recreos look
     * like, both for the day view and for deciding whether an absence leaves a recreo unwatched.
     *
     * @param AcademicYear $year    the course whose rota to read
     * @param Weekday      $weekday the weekday
     *
     * @return BreakDutyAssignment[] that day's duties, by zone then teacher
     */
    public function findByWeekday(AcademicYear $year, Weekday $weekday): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('t', 'z')
            ->join('a.teacher', 't')
            ->join('a.zone', 'z')
            ->andWhere('a.academicYear = :year')
            ->andWhere('a.weekday = :weekday')
            ->setParameter('year', $year)
            ->setParameter('weekday', $weekday)
            ->orderBy('z.sortOrder', 'ASC')
            ->addOrderBy('t.fullName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * A teacher's duty on a given weekday of a course, if they have one. At most one row exists by
     * construction (the unique key on course + teacher + weekday), which is what lets an absence resolve
     * "does this person have a recreo today?" with a single lookup.
     *
     * @param AcademicYear $year    the course whose rota to read
     * @param User         $teacher the teacher
     * @param Weekday      $weekday the weekday
     *
     * @return BreakDutyAssignment|null the duty, or null when they have none that day
     */
    public function findForTeacherAndWeekday(AcademicYear $year, User $teacher, Weekday $weekday): ?BreakDutyAssignment
    {
        return $this->createQueryBuilder('a')
            ->addSelect('z')
            ->join('a.zone', 'z')
            ->andWhere('a.academicYear = :year')
            ->andWhere('a.teacher = :teacher')
            ->andWhere('a.weekday = :weekday')
            ->setParameter('year', $year)
            ->setParameter('teacher', $teacher)
            ->setParameter('weekday', $weekday)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Whether any duty of any course still points at a zone — what makes archiving the honest gesture and
     * deleting the wrong one.
     *
     * @param BreakZone $zone the zone to check
     *
     * @return int how many duties use it
     */
    public function countByZone(BreakZone $zone): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.zone = :zone')
            ->setParameter('zone', $zone)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
