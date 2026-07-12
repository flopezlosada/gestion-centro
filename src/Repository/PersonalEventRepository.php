<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PersonalEvent;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PersonalEvent>
 */
class PersonalEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PersonalEvent::class);
    }

    /**
     * The owner's entries from a given instant onward, earliest first. Drives the personal agenda list
     * (upcoming entries); past ones live in the calendar. Scoped by owner — the privacy boundary.
     *
     * @param User               $owner the owner whose entries to list
     * @param \DateTimeImmutable $from  the earliest start instant to include (inclusive)
     *
     * @return PersonalEvent[] the owner's upcoming entries ordered by start
     */
    public function findUpcomingFor(User $owner, \DateTimeImmutable $from): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.owner = :owner')
            ->andWhere('e.startAt >= :from')
            ->setParameter('owner', $owner)
            ->setParameter('from', $from)
            ->orderBy('e.startAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The owner's entries whose start falls within an inclusive range. For the calendar/agenda, which
     * lays entries out by day. Scoped by owner — the privacy boundary.
     *
     * @param User               $owner the owner whose entries to fetch
     * @param \DateTimeImmutable $from  the first instant of the range (inclusive)
     * @param \DateTimeImmutable $to    the last instant of the range (inclusive)
     *
     * @return PersonalEvent[] the owner's entries within the range, ordered by start
     */
    public function findForOwnerBetween(User $owner, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.owner = :owner')
            ->andWhere('e.startAt BETWEEN :from AND :to')
            ->setParameter('owner', $owner)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('e.startAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Deletes every occurrence of a recurring series owned by the given user, in one query. Scoped by
     * owner — the privacy boundary — so a series id alone can never reach another user's events.
     *
     * @param User   $owner    the owner whose series to delete
     * @param string $seriesId the series identifier shared by the occurrences
     *
     * @return int the number of occurrences deleted
     */
    public function deleteSeries(User $owner, string $seriesId): int
    {
        return (int) $this->createQueryBuilder('e')
            ->delete()
            ->andWhere('e.owner = :owner')
            ->andWhere('e.seriesId = :series')
            ->setParameter('owner', $owner)
            ->setParameter('series', $seriesId)
            ->getQuery()
            ->execute();
    }
}
