<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GuardiaCover;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GuardiaCover>
 */
class GuardiaCoverRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GuardiaCover::class);
    }

    /**
     * The parte lines for a date and period, absent teacher and assigned guardia eager-loaded.
     *
     * @param \DateTimeImmutable $date      the day
     * @param int                $slotIndex the period index within the day
     *
     * @return GuardiaCover[] the covers, absent teacher first by name
     */
    public function findForParte(\DateTimeImmutable $date, int $slotIndex): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('absent', 'guardia')
            ->join('c.absentTeacher', 'absent')
            ->leftJoin('c.assignedGuardia', 'guardia')
            ->andWhere('c.date = :date')
            ->andWhere('c.slotIndex = :slot')
            ->setParameter('date', $date, 'date_immutable')
            ->setParameter('slot', $slotIndex)
            ->orderBy('absent.fullName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * How many guardias each teacher has covered at a given period — the per-slot balance the
     * equitable engine minimises. Counts every assigned cover with no incident (an assigned cover is
     * done by default). Derived live (never stored), so it cannot drift out of sync.
     *
     * @param int $slotIndex the period index within the day
     *
     * @return array<int, int> map of teacher id → cover count at that period
     */
    public function loadBySlot(int $slotIndex): array
    {
        return $this->countsKeyedByGuardia(
            $this->createQueryBuilder('c')
                ->andWhere('c.slotIndex = :slot')
                ->setParameter('slot', $slotIndex),
        );
    }

    /**
     * How many guardias each teacher has covered in total, across every period — the tiebreaker
     * when two candidates are level on the per-slot balance.
     *
     * @return array<int, int> map of teacher id → total cover count
     */
    public function totalLoad(): array
    {
        return $this->countsKeyedByGuardia($this->createQueryBuilder('c'));
    }

    /**
     * The guardias assigned to a teacher on a date, absent teacher eager-loaded — the teacher's own
     * "mis guardias de hoy" view. Ordered by period so it reads top-to-bottom through the day.
     *
     * @param User               $guardia the assigned guardia teacher
     * @param \DateTimeImmutable $date    the day
     *
     * @return GuardiaCover[] the covers assigned to that teacher that day, earliest period first
     */
    public function findAssignedTo(User $guardia, \DateTimeImmutable $date): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('absent')
            ->join('c.absentTeacher', 'absent')
            ->andWhere('c.assignedGuardia = :guardia')
            ->andWhere('c.date = :date')
            ->setParameter('guardia', $guardia)
            ->setParameter('date', $date, 'date_immutable')
            ->orderBy('c.slotIndex', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Ids of the teachers who are themselves absent on a date and period — they must be dropped from
     * the guardia pool (a teacher on call cannot cover while they are away).
     *
     * @param \DateTimeImmutable $date      the day
     * @param int                $slotIndex the period index within the day
     *
     * @return list<int> the absent teachers' ids
     */
    public function absentTeacherIdsAt(\DateTimeImmutable $date, int $slotIndex): array
    {
        /** @var list<array{id: int}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.absentTeacher) AS id')
            ->andWhere('c.date = :date')
            ->andWhere('c.slotIndex = :slot')
            ->setParameter('date', $date, 'date_immutable')
            ->setParameter('slot', $slotIndex)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    /**
     * Guardias covered per teacher across the whole course, teacher eager-loaded and ordered by count
     * (busiest first). An assigned cover with no incident counts as done; teachers with none do not
     * appear. Powers the coordinator's stats screen.
     *
     * Queried from {@see User} as root: DQL forbids selecting a *joined* entity alias alongside scalars,
     * so the teacher must be the root and the covers are joined onto it.
     *
     * @return list<array{teacher: User, total: int}> the ranking, busiest first
     */
    public function coveredTotalsByTeacher(): array
    {
        /** @var list<array{0: User, total: int}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('g', 'COUNT(c.id) AS total')
            ->from(User::class, 'g')
            ->join(GuardiaCover::class, 'c', 'WITH', 'c.assignedGuardia = g')
            ->andWhere('c.notCovered = false')
            ->groupBy('g.id')
            ->orderBy('total', 'DESC')
            ->addOrderBy('g.fullName', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (array $r): array => ['teacher' => $r[0], 'total' => (int) $r['total']],
            $rows,
        );
    }

    /**
     * How many guardias a single teacher has covered this course (assigned, no incident) — the counter
     * the teacher sees on their own screen. Derived live, like every other guardia count.
     *
     * @param User $teacher the guardia teacher
     *
     * @return int the teacher's covered-guardia count
     */
    public function countCoveredForTeacher(User $teacher): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.assignedGuardia = :teacher')
            ->andWhere('c.notCovered = false')
            ->setParameter('teacher', $teacher)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Headline coverage figures for the whole course: how many absences were registered, how many got
     * covered (assigned, no incident), how many ended as an incident (nobody covered), and how many are
     * still unassigned. The health check of the guardia service in one row.
     *
     * @return array{absences: int, covered: int, incidents: int, unassigned: int} the counts
     */
    public function coverageSummary(): array
    {
        /** @var array{absences: int|string, covered: int|string, incidents: int|string, unassigned: int|string} $row */
        $row = $this->createQueryBuilder('c')
            ->select(
                'COUNT(c.id) AS absences',
                'SUM(CASE WHEN c.assignedGuardia IS NOT NULL AND c.notCovered = false THEN 1 ELSE 0 END) AS covered',
                'SUM(CASE WHEN c.notCovered = true THEN 1 ELSE 0 END) AS incidents',
                'SUM(CASE WHEN c.assignedGuardia IS NULL AND c.notCovered = false THEN 1 ELSE 0 END) AS unassigned',
            )
            ->getQuery()
            ->getSingleResult();

        return [
            'absences' => (int) $row['absences'],
            'covered' => (int) $row['covered'],
            'incidents' => (int) $row['incidents'],
            'unassigned' => (int) $row['unassigned'],
        ];
    }

    /**
     * How many absences fell on each period this course, keyed by slot index — shows where cover is
     * needed most across the day.
     *
     * @return array<int, int> map of slot index → absence count
     */
    public function absencesBySlot(): array
    {
        /** @var list<array{slot: int, total: int}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('c.slotIndex AS slot', 'COUNT(c.id) AS total')
            ->groupBy('c.slotIndex')
            ->orderBy('c.slotIndex', 'ASC')
            ->getQuery()
            ->getResult();

        $bySlot = [];
        foreach ($rows as $row) {
            $bySlot[(int) $row['slot']] = (int) $row['total'];
        }

        return $bySlot;
    }

    /**
     * The teachers absent most this course, teacher eager-loaded, busiest first — a different lens for
     * leadership (who is away, not who covers). Queried from {@see User} as root, like
     * {@see coveredTotalsByTeacher()} (DQL cannot select a joined entity alongside scalars).
     *
     * @param int $limit how many to return
     *
     * @return list<array{teacher: User, total: int}> the ranking, most absences first
     */
    public function absencesByTeacher(int $limit = 10): array
    {
        /** @var list<array{0: User, total: int}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('g', 'COUNT(c.id) AS total')
            ->from(User::class, 'g')
            ->join(GuardiaCover::class, 'c', 'WITH', 'c.absentTeacher = g')
            ->groupBy('g.id')
            ->orderBy('total', 'DESC')
            ->addOrderBy('g.fullName', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (array $r): array => ['teacher' => $r[0], 'total' => (int) $r['total']],
            $rows,
        );
    }

    /**
     * The parte lines matching the coordinator's history filters, absent teacher and assigned guardia
     * eager-loaded, most recent first. Every filter is optional; passing none returns the full log.
     *
     * @param \DateTimeImmutable|null $from            lower date bound (inclusive)
     * @param \DateTimeImmutable|null $to              upper date bound (inclusive)
     * @param string|null             $group           exact group name to match
     * @param User|null               $assignedTeacher the guardia teacher who covered
     * @param User|null               $absentTeacher   the teacher who was absent
     *
     * @return GuardiaCover[] the matching covers, most recent first
     */
    public function history(?\DateTimeImmutable $from, ?\DateTimeImmutable $to, ?string $group, ?User $assignedTeacher, ?User $absentTeacher): array
    {
        $qb = $this->createQueryBuilder('c')
            ->addSelect('absent', 'guardia')
            ->join('c.absentTeacher', 'absent')
            ->leftJoin('c.assignedGuardia', 'guardia')
            ->orderBy('c.date', 'DESC')
            ->addOrderBy('c.slotIndex', 'ASC')
            ->addOrderBy('absent.fullName', 'ASC');

        if (null !== $from) {
            $qb->andWhere('c.date >= :from')->setParameter('from', $from, 'date_immutable');
        }
        if (null !== $to) {
            $qb->andWhere('c.date <= :to')->setParameter('to', $to, 'date_immutable');
        }
        if (null !== $group && '' !== $group) {
            $qb->andWhere('c.groupName = :group')->setParameter('group', $group);
        }
        if (null !== $assignedTeacher) {
            $qb->andWhere('c.assignedGuardia = :assigned')->setParameter('assigned', $assignedTeacher);
        }
        if (null !== $absentTeacher) {
            $qb->andWhere('c.absentTeacher = :absent')->setParameter('absent', $absentTeacher);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * The distinct group names present in the parte, alphabetically — the options for the history
     * screen's group filter.
     *
     * @return list<string> the group names present in any cover
     */
    public function distinctGroups(): array
    {
        /** @var list<array{name: string}> $rows */
        $rows = $this->createQueryBuilder('c')
            ->select('DISTINCT c.groupName AS name')
            ->andWhere('c.groupName IS NOT NULL')
            ->orderBy('c.groupName', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $r): string => $r['name'], $rows);
    }

    /**
     * Runs an incident-free, grouped-by-guardia count and returns it keyed by teacher id.
     *
     * @param \Doctrine\ORM\QueryBuilder $qb a builder already scoped (e.g. by slot), alias {@code c}
     *
     * @return array<int, int> map of teacher id → cover count
     */
    private function countsKeyedByGuardia(\Doctrine\ORM\QueryBuilder $qb): array
    {
        /** @var list<array{id: int, total: int}> $rows */
        $rows = $qb
            ->select('IDENTITY(c.assignedGuardia) AS id', 'COUNT(c.id) AS total')
            ->andWhere('c.notCovered = false')
            ->andWhere('c.assignedGuardia IS NOT NULL')
            ->groupBy('c.assignedGuardia')
            ->getQuery()
            ->getResult();

        $load = [];
        foreach ($rows as $row) {
            $load[(int) $row['id']] = (int) $row['total'];
        }

        return $load;
    }
}
