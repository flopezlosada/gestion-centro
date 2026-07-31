<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\BreakDutyAssignment;
use App\Entity\BreakZone;
use App\Entity\User;
use App\Enum\BreakDutySource;
use App\Enum\BreakPeriod;
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
     * Brings the engine's places in a course into line with a fresh set, in one transaction.
     *
     * A DIFF, not a wipe-and-rewrite, and that is not an optimisation. Every {@see BreakDutyGap} hangs off
     * its place with {@code ON DELETE CASCADE}, so deleting a place takes with it the record of every day
     * that recreo went unwatched — and those pile up over the course on perfectly ordinary engine places.
     * Re-publishing after nudging a quota in January would have quietly erased months of that history.
     * Places that come out of the new proposal unchanged are therefore left exactly where they are, with
     * their gaps; only the ones that really disappear are deleted.
     *
     * Places somebody added by hand are never touched either way.
     *
     * @param AcademicYear              $year   the course being redrawn
     * @param list<BreakDutyAssignment> $wanted the places the new proposal asks for
     *
     * @return array{kept: int, added: int, removed: int} what the sync did
     */
    public function syncEnginePlaces(AcademicYear $year, array $wanted): array
    {
        $em = $this->getEntityManager();
        $key = static fn (BreakDutyAssignment $p): string => $p->getTeacher()->getId().':'.$p->getWeekday()->value.':'.$p->getPeriod()->value.':'.$p->getZone()->getId();

        $current = [];
        foreach ($this->findByYear($year) as $place) {
            if (BreakDutySource::ENGINE === $place->getSource()) {
                $current[$key($place)] = $place;
            }
        }

        $kept = 0;
        $added = 0;
        $seen = [];
        foreach ($wanted as $place) {
            $k = $key($place);
            $seen[$k] = true;
            if (isset($current[$k])) {
                ++$kept;
                continue;
            }
            $em->persist($place);
            ++$added;
        }

        $removed = 0;
        foreach ($current as $k => $place) {
            if (!isset($seen[$k])) {
                $em->remove($place);
                ++$removed;
            }
        }

        $em->wrapInTransaction(static function () use ($em): void {
            $em->flush();
        });

        return ['kept' => $kept, 'added' => $added, 'removed' => $removed];
    }

    /**
     * A teacher's places on a given weekday of a course — up to two, one per recreo.
     *
     * A list, not a single row: since a place belongs to one recreo, somebody can watch the patio at the
     * long one and the biblioteca at the short one. The old single-row version would now throw the moment
     * anybody did.
     *
     * @param AcademicYear $year    the course whose rota to read
     * @param User         $teacher the teacher
     * @param Weekday      $weekday the weekday
     *
     * @return BreakDutyAssignment[] their places that day, earliest recreo first
     */
    public function findAllForTeacherAndWeekday(AcademicYear $year, User $teacher, Weekday $weekday): array
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
            ->orderBy('a.period', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The place a teacher holds at one specific recreo of a weekday, if any — what the pre-check before
     * adding a place asks, since that is the only clash the unique key forbids.
     *
     * @param AcademicYear $year    the course whose rota to read
     * @param User         $teacher the teacher
     * @param Weekday      $weekday the weekday
     * @param BreakPeriod  $period  which recreo
     *
     * @return BreakDutyAssignment|null the place, or null when they have none there
     */
    public function findForTeacherWeekdayAndPeriod(AcademicYear $year, User $teacher, Weekday $weekday, BreakPeriod $period): ?BreakDutyAssignment
    {
        return $this->createQueryBuilder('a')
            ->addSelect('z')
            ->join('a.zone', 'z')
            ->andWhere('a.academicYear = :year')
            ->andWhere('a.teacher = :teacher')
            ->andWhere('a.weekday = :weekday')
            ->andWhere('a.period = :period')
            ->setParameter('year', $year)
            ->setParameter('teacher', $teacher)
            ->setParameter('weekday', $weekday)
            ->setParameter('period', $period)
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
