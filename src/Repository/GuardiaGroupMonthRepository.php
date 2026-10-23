<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GuardiaGroupMonth;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The archived half of the periods each group spent without its teacher. Read through
 * {@see GuardiaCoverRepository::periodsByGroup()}, which adds the live covers to it.
 *
 * @extends ServiceEntityRepository<GuardiaGroupMonth>
 */
class GuardiaGroupMonthRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GuardiaGroupMonth::class);
    }

    /**
     * The archived periods per group within a window. Only whole months are kept, so a month counts whole
     * as soon as the window touches it, at either end: a term starting on the 9th still takes that month's
     * first week, and one ending on the 20th the archived days after it.
     *
     * @param \DateTimeImmutable|null $from lower date bound (inclusive), or null
     * @param \DateTimeImmutable|null $to   upper date bound (inclusive), or null
     *
     * @return array<string, int> group → periods without its teacher
     */
    public function periodsByGroup(?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        $qb = $this->createQueryBuilder('m')
            ->select('m.groupName AS name', 'SUM(m.periods) AS total')
            ->groupBy('m.groupName');
        if (null !== $from) {
            $qb->andWhere('m.month >= :from')->setParameter('from', $from->modify('first day of this month')->setTime(0, 0), 'date_immutable');
        }
        if (null !== $to) {
            $qb->andWhere('m.month <= :to')->setParameter('to', $to, 'date_immutable');
        }

        /** @var list<array{name: string, total: int|string}> $rows */
        $rows = $qb->getQuery()->getResult();

        return array_column(
            array_map(static fn (array $r): array => ['name' => $r['name'], 'total' => (int) $r['total']], $rows),
            'total',
            'name',
        );
    }
}
