<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Booking;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Booking>
 */
class BookingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Booking::class);
    }

    /**
     * Everything booked on a day, ordered by period. It is the whole screen: one grid of what is taken
     * and by whom, which is what stops two people asking for the same thing.
     *
     * @param \DateTimeImmutable $date the day
     *
     * @return Booking[] the day's bookings, earliest period first
     */
    public function findForDay(\DateTimeImmutable $date): array
    {
        return $this->createQueryBuilder('b')
            // Las tres se pintan en cada fila: se traen en la misma consulta (evita un N+1).
            ->leftJoin('b.room', 'room')->addSelect('room')
            ->leftJoin('b.material', 'material')->addSelect('material')
            ->leftJoin('b.bookedBy', 'person')->addSelect('person')
            ->andWhere('b.date = :date')->setParameter('date', $date->setTime(0, 0))
            ->orderBy('b.slotIndex', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * A person's own bookings from a day onwards, so they can see (and undo) what they have asked for.
     *
     * @param User               $user the person
     * @param \DateTimeImmutable $from the first day to include
     *
     * @return Booking[] their bookings, soonest first
     */
    public function findUpcomingFor(User $user, \DateTimeImmutable $from): array
    {
        return $this->createQueryBuilder('b')
            ->leftJoin('b.room', 'room')->addSelect('room')
            ->leftJoin('b.material', 'material')->addSelect('material')
            ->andWhere('b.bookedBy = :user')->setParameter('user', $user)
            ->andWhere('b.date >= :from')->setParameter('from', $from->setTime(0, 0))
            ->orderBy('b.date', 'ASC')->addOrderBy('b.slotIndex', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
