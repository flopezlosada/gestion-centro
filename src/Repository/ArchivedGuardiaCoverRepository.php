<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ArchivedGuardiaCover;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The archived half of every guardia count.
 *
 * Read only through {@see GuardiaCoverRepository}, which adds these figures to the live ones: the fair
 * split, the teacher's tally and the statistics must not change because a day passed the plazo, and one
 * place doing the sum is what keeps all of them agreeing.
 *
 * @extends ServiceEntityRepository<ArchivedGuardiaCover>
 */
class ArchivedGuardiaCoverRepository extends ServiceEntityRepository
{
    /**
     * What one archived guardia costs, as {@see GuardiaCoverRepository::WORK_UNIT} for the live ones: the
     * covers of a grouping count once between them. The two never need merging into one count, because a
     * grouping is of one day and a day is archived whole.
     */
    private const string WORK_UNIT = "COALESCE(CONCAT('g', a.groupingKey), CONCAT('a', a.id))";

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArchivedGuardiaCover::class);
    }

    /**
     * The archived guardias each teacher did, a grouping counting as one.
     *
     * @param int|null $slotIndex only those of this period, or null for all of them
     *
     * @return array<int, int> teacher id → guardias done
     */
    public function loadByTeacher(?int $slotIndex = null): array
    {
        $qb = $this->doneQuery()
            ->select('IDENTITY(a.assignedGuardia) AS id', 'COUNT(DISTINCT '.self::WORK_UNIT.') AS total')
            ->andWhere('a.assignedGuardia IS NOT NULL')
            ->groupBy('a.assignedGuardia');
        if (null !== $slotIndex) {
            $qb->andWhere('a.slotIndex = :slot')->setParameter('slot', $slotIndex);
        }

        /** @var list<array{id: int|string, total: int|string}> $rows */
        $rows = $qb->getQuery()->getResult();

        $load = [];
        foreach ($rows as $row) {
            $load[(int) $row['id']] = (int) $row['total'];
        }

        return $load;
    }

    /**
     * The archived guardias one teacher did, a grouping counting as one.
     *
     * @param User $teacher the guardia teacher
     *
     * @return int their count
     */
    public function countFor(User $teacher): int
    {
        return (int) $this->doneQuery()
            ->select('COUNT(DISTINCT '.self::WORK_UNIT.')')
            ->andWhere('a.assignedGuardia = :teacher')
            ->setParameter('teacher', $teacher)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The archived guardias per teacher within a window, teacher loaded, as
     * {@see GuardiaCoverRepository::coveredTotalsByTeacher()} needs them to add up.
     *
     * @param \DateTimeImmutable|null $from lower date bound (inclusive), or null
     * @param \DateTimeImmutable|null $to   upper date bound (inclusive), or null
     *
     * @return list<array{teacher: User, total: int}> one row per teacher with any
     */
    public function totalsByTeacher(?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('g', 'COUNT(DISTINCT '.self::WORK_UNIT.') AS total')
            ->from(User::class, 'g')
            ->join(ArchivedGuardiaCover::class, 'a', 'WITH', 'a.assignedGuardia = g')
            ->andWhere('a.notCovered = false')
            ->groupBy('g.id');
        $this->applyWindow($qb, $from, $to);

        /** @var list<array{0: User, total: int|string}> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_map(static fn (array $r): array => ['teacher' => $r[0], 'total' => (int) $r['total']], $rows);
    }

    /**
     * The coverage figures of the archived guardias within a window, as
     * {@see GuardiaCoverRepository::coverageSummary()} counts them.
     *
     * @param \DateTimeImmutable|null $from lower date bound (inclusive), or null
     * @param \DateTimeImmutable|null $to   upper date bound (inclusive), or null
     *
     * @return array{absences: int, covered: int, incidents: int, unassigned: int} the counts
     */
    public function coverageSummary(?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        $qb = $this->createQueryBuilder('a')
            ->select(
                'COUNT(a.id) AS absences',
                'SUM(CASE WHEN a.assignedGuardia IS NOT NULL AND a.notCovered = false THEN 1 ELSE 0 END) AS covered',
                'SUM(CASE WHEN a.notCovered = true THEN 1 ELSE 0 END) AS incidents',
                'SUM(CASE WHEN a.assignedGuardia IS NULL AND a.notCovered = false THEN 1 ELSE 0 END) AS unassigned',
            );
        $this->applyWindow($qb, $from, $to);

        /** @var array{absences: int|string, covered: int|string|null, incidents: int|string|null, unassigned: int|string|null} $row */
        $row = $qb->getQuery()->getSingleResult();

        return [
            'absences' => (int) $row['absences'],
            'covered' => (int) $row['covered'],
            'incidents' => (int) $row['incidents'],
            'unassigned' => (int) $row['unassigned'],
        ];
    }

    /**
     * The archived guardias as rows for the analytics, as {@see GuardiaCoverRepository::analyticsRows()}.
     *
     * @param \DateTimeImmutable|null $from lower date bound (inclusive), or null
     * @param \DateTimeImmutable|null $to   upper date bound (inclusive), or null
     *
     * @return list<array{date: \DateTimeImmutable, slot: int, assigned: bool, incident: bool}> the rows
     */
    public function analyticsRows(?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        $qb = $this->createQueryBuilder('a')
            ->select('a.date AS date', 'a.slotIndex AS slot', 'IDENTITY(a.assignedGuardia) AS assigned', 'a.notCovered AS incident');
        $this->applyWindow($qb, $from, $to);

        /** @var list<array{date: \DateTimeImmutable, slot: int|string, assigned: int|string|null, incident: bool}> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_map(
            static fn (array $r): array => [
                'date' => $r['date'],
                'slot' => (int) $r['slot'],
                'assigned' => null !== $r['assigned'],
                'incident' => (bool) $r['incident'],
            ],
            $rows,
        );
    }

    /**
     * The archived guardias within a window, most recent first, whoever did them loaded — the tail of the
     * coordination's history.
     *
     * @param \DateTimeImmutable|null $from lower date bound (inclusive), or null
     * @param \DateTimeImmutable|null $to   upper date bound (inclusive), or null
     *
     * @return list<ArchivedGuardiaCover> the guardias
     */
    public function findBetween(?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        $qb = $this->createQueryBuilder('a')
            ->addSelect('guardia')
            ->leftJoin('a.assignedGuardia', 'guardia')
            ->orderBy('a.date', 'DESC')
            ->addOrderBy('a.slotIndex', 'ASC');
        $this->applyWindow($qb, $from, $to);

        /** @var list<ArchivedGuardiaCover> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    /**
     * The archived guardias one teacher did, most recent first — the tail of their own history.
     *
     * @param User $teacher the guardia teacher
     *
     * @return list<ArchivedGuardiaCover> the guardias
     */
    public function findAssignedTo(User $teacher): array
    {
        /** @var list<ArchivedGuardiaCover> $rows */
        $rows = $this->createQueryBuilder('a')
            ->andWhere('a.assignedGuardia = :teacher')
            ->setParameter('teacher', $teacher)
            ->orderBy('a.date', 'DESC')
            ->addOrderBy('a.slotIndex', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * The guardias that count as done: those that took place.
     *
     * @return QueryBuilder a builder over them, alias {@code a}
     */
    private function doneQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('a')->andWhere('a.notCovered = false');
    }

    /**
     * Constrains a query to an inclusive date window, skipping a bound that is null.
     *
     * @param QueryBuilder            $qb   a builder with the archived cover as {@code a}
     * @param \DateTimeImmutable|null $from lower bound (inclusive), or null
     * @param \DateTimeImmutable|null $to   upper bound (inclusive), or null
     */
    private function applyWindow(QueryBuilder $qb, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): void
    {
        if (null !== $from) {
            $qb->andWhere('a.date >= :winFrom')->setParameter('winFrom', $from, 'date_immutable');
        }
        if (null !== $to) {
            $qb->andWhere('a.date <= :winTo')->setParameter('winTo', $to, 'date_immutable');
        }
    }
}
