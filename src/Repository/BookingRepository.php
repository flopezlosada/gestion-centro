<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Booking;
use App\Entity\Material;
use App\Entity\RecurringBooking;
use App\Entity\Room;
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
            ->leftJoin('b.series', 'series')->addSelect('series')
            ->leftJoin('series.holderUser', 'holder')->addSelect('holder')
            ->leftJoin('series.holderDepartment', 'dept')->addSelect('dept')
            ->andWhere('b.date = :date')->setParameter('date', $date->setTime(0, 0))
            ->orderBy('b.slotIndex', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Everything booked between two days, inclusive — the tracking sheet the equipo directivo asked for
     * ("who had which room, when, with which group"), a week at a time.
     *
     * Ordered by day and then period so the listing reads as the week goes; the person and the resource are
     * joined in because every row shows both and the sheet would otherwise be an N+1 over the whole week.
     *
     * @param \DateTimeImmutable $from the first day to include
     * @param \DateTimeImmutable $to   the last day to include
     *
     * @return Booking[] the bookings in the range, earliest first
     */
    public function findForRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('b')
            ->leftJoin('b.room', 'room')->addSelect('room')
            ->leftJoin('b.material', 'material')->addSelect('material')
            ->leftJoin('b.bookedBy', 'person')->addSelect('person')
            ->leftJoin('b.series', 'series')->addSelect('series')
            ->leftJoin('series.holderUser', 'holder')->addSelect('holder')
            ->leftJoin('series.holderDepartment', 'dept')->addSelect('dept')
            ->andWhere('b.date >= :from')->setParameter('from', $from->setTime(0, 0))
            ->andWhere('b.date <= :to')->setParameter('to', $to->setTime(0, 0))
            ->orderBy('b.date', 'ASC')->addOrderBy('b.slotIndex', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Everything already booked of one thing between two days, inclusive: what a new fixed booking would
     * clash with. One query for the whole stretch, however long — the alternative is asking day by day for
     * a course.
     *
     * @param Room|Material      $resource the room or material
     * @param \DateTimeImmutable $from     the first day to include
     * @param \DateTimeImmutable $to       the last day to include
     *
     * @return Booking[] its bookings in the range, earliest first
     */
    public function findForResourceBetween(Room|Material $resource, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('b')
            ->leftJoin('b.bookedBy', 'person')->addSelect('person')
            ->leftJoin('b.series', 'series')->addSelect('series')
            ->andWhere($resource instanceof Room ? 'b.room = :resource' : 'b.material = :resource')->setParameter('resource', $resource)
            ->andWhere('b.date >= :from')->setParameter('from', $from->setTime(0, 0))
            ->andWhere('b.date <= :to')->setParameter('to', $to->setTime(0, 0))
            ->orderBy('b.date', 'ASC')->addOrderBy('b.slotIndex', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Removes the days of a fixed booking from a day onwards: what ending it early leaves behind. The days
     * already past stay, as the record of who had it.
     *
     * @param RecurringBooking   $series the fixed booking
     * @param \DateTimeImmutable $from   the first day to remove
     *
     * @return int how many bookings were removed
     */
    public function deleteSeriesFrom(RecurringBooking $series, \DateTimeImmutable $from): int
    {
        return (int) $this->createQueryBuilder('b')
            ->delete()
            ->andWhere('b.series = :series')->setParameter('series', $series)
            ->andWhere('b.date >= :from')->setParameter('from', $from->setTime(0, 0))
            ->getQuery()
            ->execute();
    }

    /**
     * A person's own bookings from a day onwards, so they can see (and undo) what they have asked for.
     *
     * Only the one-off ones: the days of a fixed booking are a whole course of rows, and they have their own
     * screen, where they read as one line.
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
            ->andWhere('b.series IS NULL')
            ->andWhere('b.date >= :from')->setParameter('from', $from->setTime(0, 0))
            ->orderBy('b.date', 'ASC')->addOrderBy('b.slotIndex', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
