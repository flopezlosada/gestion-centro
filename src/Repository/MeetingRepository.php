<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Meeting;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Every query here is scoped to the meetings a person is part of ({@see Meeting::concerns()}: they
 * convened it or they are convened), which is the same rule the visibility gate and the minutes download
 * apply. A meeting is not public: a teacher never sees one they were not called to.
 *
 * @extends ServiceEntityRepository<Meeting>
 */
class MeetingRepository extends ServiceEntityRepository
{
    /** How many past meetings the archive of a single screen shows, newest first. */
    private const int PAST_LIMIT = 40;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Meeting::class);
    }

    /**
     * The person's meetings starting at or after the given instant, earliest first. Drives both the
     * agenda (Inicio) and the "próximas" block of the meetings list.
     *
     * @param User               $user the person the meetings must concern
     * @param \DateTimeImmutable $from the earliest start instant to include (inclusive)
     *
     * @return list<Meeting> the upcoming meetings, ordered by start
     */
    public function findUpcomingFor(User $user, \DateTimeImmutable $from): array
    {
        return $this->concerning($user)
            ->andWhere('m.startAt >= :from')
            ->setParameter('from', $from)
            ->orderBy('m.startAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The person's meetings already started, most recent first — the archive where the minutes live.
     * Capped at {@see self::PAST_LIMIT}: older ones are reached from the project, not from this list.
     *
     * @param User               $user   the person the meetings must concern
     * @param \DateTimeImmutable $before the instant before which a meeting counts as past (exclusive)
     *
     * @return list<Meeting> the past meetings, most recent first
     */
    public function findPastFor(User $user, \DateTimeImmutable $before): array
    {
        return $this->concerning($user)
            ->andWhere('m.startAt < :before')
            ->setParameter('before', $before)
            ->orderBy('m.startAt', 'DESC')
            ->setMaxResults(self::PAST_LIMIT)
            ->getQuery()
            ->getResult();
    }

    /**
     * The person's meetings whose start falls within an inclusive range, for the calendar (which lays
     * them out by day).
     *
     * @param User               $user the person the meetings must concern
     * @param \DateTimeImmutable $from the first instant of the range (inclusive)
     * @param \DateTimeImmutable $to   the last instant of the range (inclusive)
     *
     * @return list<Meeting> the meetings within the range, ordered by start
     */
    public function findForUserBetween(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->concerning($user)
            ->andWhere('m.startAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('m.startAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The meetings of a project, most recent first — the project's own record of what was agreed.
     * Deliberately NOT scoped to a person: it is read from the admin project detail, which already
     * requires administration access.
     *
     * @param Project $project the project
     *
     * @return list<Meeting> the project's meetings, most recent first
     */
    public function findForProject(Project $project): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.project = :project')
            ->setParameter('project', $project)
            ->orderBy('m.startAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The meetings whose push reminder is due: the instant has arrived, nothing was sent yet and the
     * meeting has NOT started. Deliberately NOT scoped to a person — this is the only query in the class
     * that is not, and it exists to notify each meeting's own people ({@see Meeting::people()}), never to
     * show one to anybody else.
     *
     * Skipping meetings already under way is what makes a late run harmless: after an outage the sweep
     * catches up on what is still ahead instead of pushing "empieza en 10 minutos" about a meeting that
     * started an hour ago.
     *
     * @param \DateTimeImmutable $now the sweep instant
     *
     * @return list<Meeting> the meetings to remind about, earliest first
     */
    public function findDueReminders(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.remindAt IS NOT NULL')
            ->andWhere('m.remindAt <= :now')
            ->andWhere('m.reminderSentAt IS NULL')
            ->andWhere('m.startAt >= :now')
            ->setParameter('now', $now)
            ->orderBy('m.startAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Base query for "the meetings this person is part of": convened by them or with them on the list.
     * The join is only a filter, so the result is DISTINCT — otherwise a meeting would come back once
     * per attendee row it matched.
     *
     * @param User $user the person
     *
     * @return QueryBuilder the scoped query builder, aliased "m"
     */
    private function concerning(User $user): QueryBuilder
    {
        return $this->createQueryBuilder('m')
            ->leftJoin('m.attendees', 'a')
            ->andWhere('m.convener = :user OR a = :user')
            ->setParameter('user', $user)
            ->distinct();
    }
}
