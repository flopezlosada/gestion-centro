<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FamilyActivity;
use App\Entity\OneOffFamilyActivity;
use App\Entity\WeeklyFamilyActivity;
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
     * The one-off activities starting within a span, soonest first — a week of the calendar.
     *
     * @param \DateTimeImmutable $from the first instant (inclusive)
     * @param \DateTimeImmutable $to   the last instant (exclusive)
     *
     * @return list<OneOffFamilyActivity> the activities
     */
    public function findOneOffsBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->query(OneOffFamilyActivity::class)
            ->andWhere('a.startAt >= :from')->setParameter('from', $from)
            ->andWhere('a.startAt < :to')->setParameter('to', $to)
            ->orderBy('a.startAt', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The one-off activities still to come (or happening today), soonest first — what is being planned.
     *
     * @param \DateTimeImmutable $today the current day; anything starting on it or later is listed
     *
     * @return list<OneOffFamilyActivity> the activities
     */
    public function findUpcomingOneOffs(\DateTimeImmutable $today): array
    {
        return $this->query(OneOffFamilyActivity::class)
            ->andWhere('a.startAt >= :from')->setParameter('from', $today->setTime(0, 0))
            ->orderBy('a.startAt', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The weekly activities of some courses, by day of the week and period.
     *
     * @param list<string> $schoolYears the courses, as "2026-2027"
     *
     * @return list<WeeklyFamilyActivity> the activities
     */
    public function findWeeklyIn(array $schoolYears): array
    {
        return $this->query(WeeklyFamilyActivity::class)
            ->andWhere('a.schoolYear IN (:years)')->setParameter('years', $schoolYears)
            ->orderBy('a.weekday', 'ASC')
            ->addOrderBy('a.slotIndex', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The base of every list. The organiser and the families coming are printed with every session:
     * fetch-joined to avoid an N+1. The signups come whole, every session of the course, never filtered
     * to the week shown: the rules of {@see FamilyActivity} (places left, signing up twice, moving them
     * to another day) read the collection, and a filtered fetch-join would hand them half of it. A
     * handful of families per activity keeps it small.
     *
     * @param class-string<FamilyActivity> $kind which kind of activity
     *
     * @return QueryBuilder the query, without filter or order
     */
    private function query(string $kind): QueryBuilder
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('a')->from($kind, 'a')
            ->leftJoin('a.organizer', 'organizer')->addSelect('organizer')
            ->leftJoin('a.signups', 'signup')->addSelect('signup')
            ->leftJoin('signup.family', 'family')->addSelect('family');
    }
}
