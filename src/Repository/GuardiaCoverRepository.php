<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GuardiaCover;
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
     * How many confirmed guardias each teacher has done at a given period — the per-slot balance the
     * equitable engine minimises. Derived live (never stored), so it cannot drift out of sync.
     *
     * @param int $slotIndex the period index within the day
     *
     * @return array<int, int> map of teacher id → confirmed cover count at that period
     */
    public function confirmedLoadBySlot(int $slotIndex): array
    {
        return $this->confirmedCountsKeyedByGuardia(
            $this->createQueryBuilder('c')
                ->andWhere('c.slotIndex = :slot')
                ->setParameter('slot', $slotIndex),
        );
    }

    /**
     * How many confirmed guardias each teacher has done in total, across every period — the tiebreaker
     * when two candidates are level on the per-slot balance.
     *
     * @return array<int, int> map of teacher id → total confirmed cover count
     */
    public function confirmedTotalLoad(): array
    {
        return $this->confirmedCountsKeyedByGuardia($this->createQueryBuilder('c'));
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
     * Runs a confirmed-only, grouped-by-guardia count and returns it keyed by teacher id.
     *
     * @param \Doctrine\ORM\QueryBuilder $qb a builder already scoped (e.g. by slot), alias {@code c}
     *
     * @return array<int, int> map of teacher id → confirmed cover count
     */
    private function confirmedCountsKeyedByGuardia(\Doctrine\ORM\QueryBuilder $qb): array
    {
        /** @var list<array{id: int, total: int}> $rows */
        $rows = $qb
            ->select('IDENTITY(c.assignedGuardia) AS id', 'COUNT(c.id) AS total')
            ->andWhere('c.confirmed = true')
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
