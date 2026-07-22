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
