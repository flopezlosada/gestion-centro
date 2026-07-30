<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GuardiaGrouping;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GuardiaGrouping>
 */
class GuardiaGroupingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GuardiaGrouping::class);
    }

    /**
     * The groupings arranged for a date and period — what the parte shows next to each line and what the
     * grouping screen lists as already sorted out.
     *
     * @param \DateTimeImmutable $date      the day
     * @param int                $slotIndex the period index within the day
     *
     * @return GuardiaGrouping[] the groupings, by room
     */
    public function findForSlot(\DateTimeImmutable $date, int $slotIndex): array
    {
        return $this->createQueryBuilder('g')
            ->andWhere('g.date = :date')
            ->andWhere('g.slotIndex = :slot')
            ->setParameter('date', $date, 'date_immutable')
            ->setParameter('slot', $slotIndex)
            ->orderBy('g.roomName', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
