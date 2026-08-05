<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AcademicYear;
use App\Entity\BreakDutyAssignment;
use App\Entity\BreakDutyGap;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BreakDutyGap>
 */
class BreakDutyGapRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BreakDutyGap::class);
    }

    /**
     * The gap already recorded for a duty on a day, if any. This is the lookup that keeps the alert to
     * the equipo directivo down to one per (duty, day): registering an absence again — first two periods,
     * then the whole day — finds the gap instead of creating a second one.
     *
     * @param BreakDutyAssignment $assignment the rota line
     * @param \DateTimeImmutable  $date       the day
     *
     * @return BreakDutyGap|null the gap, or null when the day is not recorded yet
     */
    public function findForAssignmentAndDate(BreakDutyAssignment $assignment, \DateTimeImmutable $date): ?BreakDutyGap
    {
        return $this->findOneBy(['assignment' => $assignment, 'date' => $date]);
    }

    /**
     * Which of these places are already recorded as a gap on a day — the question Inicio asks before
     * telling somebody "hoy te toca el patio": a teacher who has already registered that they are away
     * must not be reminded of a duty their own absence has released.
     *
     * One query for the lot rather than one per place: it runs on the busiest screen of the app, and a
     * teacher can hold both recreos of a day.
     *
     * @param BreakDutyAssignment[] $assignments the places to check (an empty list asks nothing)
     * @param \DateTimeImmutable    $date        the day
     *
     * @return list<int> the ids of the places with a gap that day
     */
    public function findAssignmentIdsWithGapOn(array $assignments, \DateTimeImmutable $date): array
    {
        if ([] === $assignments) {
            return [];
        }

        /** @var list<array{id: int}> $rows */
        $rows = $this->createQueryBuilder('g')
            ->select('IDENTITY(g.assignment) AS id')
            ->andWhere('g.assignment IN (:assignments)')
            ->andWhere('g.date = :date')
            ->setParameter('assignments', $assignments)
            ->setParameter('date', $date)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * The gaps of one day, with the duty, its zone and the absent teacher joined — the "hoy falta gente
     * en el recreo" panel the equipo directivo works from.
     *
     * @param \DateTimeImmutable $date the day
     *
     * @return BreakDutyGap[] that day's gaps, by zone
     */
    public function findByDate(\DateTimeImmutable $date): array
    {
        return $this->joinedQuery()
            ->andWhere('g.date = :date')
            ->setParameter('date', $date)
            ->orderBy('z.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The gaps of a course within an optional window, most recent first — the history behind "cuántos
     * recreos se quedaron sin vigilar este trimestre".
     *
     * @param AcademicYear            $year the course whose rota the gaps belong to
     * @param \DateTimeImmutable|null $from window start, inclusive, or null for no lower bound
     * @param \DateTimeImmutable|null $to   window end, inclusive, or null for no upper bound
     *
     * @return BreakDutyGap[] the gaps, most recent first
     */
    public function findByYear(AcademicYear $year, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): array
    {
        $qb = $this->joinedQuery()
            ->andWhere('a.academicYear = :year')
            ->setParameter('year', $year)
            ->orderBy('g.date', 'DESC')
            ->addOrderBy('z.sortOrder', 'ASC');

        if (null !== $from) {
            $qb->andWhere('g.date >= :from')->setParameter('from', $from);
        }
        if (null !== $to) {
            $qb->andWhere('g.date <= :to')->setParameter('to', $to);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * How many gaps a course accumulated and how many of those a volunteer ended up covering — the two
     * numbers that say whether "no se cubre" is working out in practice.
     *
     * @param AcademicYear            $year the course whose rota the gaps belong to
     * @param \DateTimeImmutable|null $from window start, inclusive, or null for no lower bound
     * @param \DateTimeImmutable|null $to   window end, inclusive, or null for no upper bound
     *
     * @return array{total: int, covered: int} the counts
     */
    public function summary(AcademicYear $year, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): array
    {
        $qb = $this->createQueryBuilder('g')
            ->select('COUNT(g.id) AS total', 'SUM(CASE WHEN g.volunteer IS NOT NULL THEN 1 ELSE 0 END) AS covered')
            ->join('g.assignment', 'a')
            ->andWhere('a.academicYear = :year')
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

    /**
     * Base query with everything a gap is read with: its duty, that duty's zone and the teacher who was
     * away. Loaded together because no screen shows a gap without naming all three.
     *
     * @return \Doctrine\ORM\QueryBuilder the joined query
     */
    private function joinedQuery(): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('g')
            ->addSelect('a', 'z', 't')
            ->join('g.assignment', 'a')
            ->join('a.zone', 'z')
            ->join('a.teacher', 't');
    }
}
