<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RecurringBooking;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RecurringBooking>
 */
class RecurringBookingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecurringBooking::class);
    }

    /**
     * The fixed bookings still in force on a day or after it, by what they hold: the list on the screen of
     * fixed bookings. Those already over are history and are read on the tracking sheet, not here.
     *
     * @param \DateTimeImmutable $from the first day that counts
     *
     * @return RecurringBooking[] the series, by resource name and then start
     */
    public function findCurrent(\DateTimeImmutable $from): array
    {
        return $this->createQueryBuilder('s')
            // Todo lo que pinta cada fila, en la misma consulta (evita un N+1 sobre la lista).
            ->leftJoin('s.room', 'room')->addSelect('room')
            ->leftJoin('s.material', 'material')->addSelect('material')
            ->leftJoin('s.holderUser', 'holder')->addSelect('holder')
            ->leftJoin('s.holderDepartment', 'dept')->addSelect('dept')
            ->leftJoin('s.createdBy', 'creator')->addSelect('creator')
            ->andWhere('s.endsOn >= :from')->setParameter('from', $from->setTime(0, 0))
            ->orderBy('material.name', 'ASC')->addOrderBy('room.name', 'ASC')->addOrderBy('s.startsOn', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
