<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SpacePlan;
use App\Entity\SpacePlanAssignment;
use App\Entity\User;
use App\Enum\SpacePlanStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SpacePlanAssignment>
 */
class SpacePlanAssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpacePlanAssignment::class);
    }

    /**
     * The lines that are actually in force at one period of one date: those of the CHOSEN option of an
     * APPROVED plan. Everything else — drafts, alternatives that were not picked — says nothing about
     * reality and must never reach the effective timetable.
     *
     * @param \DateTimeImmutable $date      the day
     * @param int                $slotIndex the period index
     *
     * @return SpacePlanAssignment[] the lines in force, rooms and source cells joined
     */
    public function inForceAt(\DateTimeImmutable $date, int $slotIndex): array
    {
        return $this->inForceQuery()
            ->addSelect('r', 'src')
            ->leftJoin('a.room', 'r')
            ->leftJoin('a.sourceEntry', 'src')
            ->andWhere('a.date = :date')
            ->andWhere('a.slotIndex = :slot')
            ->setParameter('date', $date)
            ->setParameter('slot', $slotIndex)
            ->getQuery()
            ->getResult();
    }

    /**
     * The lines in force between two dates that concern a teacher — their own agenda of "where am I this
     * week", and what a notice about a change has to list.
     *
     * @param User               $teacher the teacher
     * @param \DateTimeImmutable $from    first day, inclusive
     * @param \DateTimeImmutable $to      last day, inclusive
     *
     * @return SpacePlanAssignment[] the lines, earliest first
     */
    public function inForceForTeacher(User $teacher, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->inForceQuery()
            // 'o' has to be selected alongside 'p': Doctrine cannot hydrate a joined entity whose parent
            // alias is absent from the SELECT ("The parent object of entity result with alias 'p' was not
            // found"). Caught in runtime, not by PHPStan.
            ->addSelect('r', 'o', 'p')
            ->leftJoin('a.room', 'r')
            ->andWhere('a.teacher = :teacher')
            ->andWhere('a.date BETWEEN :from AND :to')
            ->setParameter('teacher', $teacher)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('a.date', 'ASC')
            ->addOrderBy('a.slotIndex', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The lines already in force, between two dates, that belong to some OTHER plan — what a plan about
     * to be approved has to be checked against. Two plans that each looked fine on their own can still
     * send two groups to the same door.
     *
     * Read in ONE query and keyed by "roomId|date|slot" so the check is a lookup per candidate line
     * rather than a query per line (a week-long exam plan carries dozens).
     *
     * @param SpacePlan          $exclude the plan being approved, whose own lines do not count
     * @param \DateTimeImmutable $from    first day, inclusive
     * @param \DateTimeImmutable $to      last day, inclusive
     *
     * @return array<string, SpacePlanAssignment> the occupying line, keyed by "roomId|Y-m-d|slot"
     */
    public function inForceByRoomSlot(SpacePlan $exclude, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var SpacePlanAssignment[] $rows */
        $rows = $this->inForceQuery()
            // See inForceForTeacher(): 'p' cannot be hydrated without its parent alias 'o'.
            ->addSelect('r', 'o', 'p')
            ->leftJoin('a.room', 'r')
            ->andWhere('a.date BETWEEN :from AND :to')
            ->andWhere('o.plan != :plan')
            ->andWhere('a.room IS NOT NULL')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('plan', $exclude)
            ->getQuery()
            ->getResult();

        $byKey = [];
        foreach ($rows as $row) {
            $byKey[self::key($row)] = $row;
        }

        return $byKey;
    }

    /**
     * The lookup key of a line's room-and-moment, shared by whoever builds the index and whoever probes
     * it — so the two can never drift apart.
     *
     * @param SpacePlanAssignment $assignment the line
     *
     * @return string the key, or an empty string when the line has no room yet
     */
    public static function key(SpacePlanAssignment $assignment): string
    {
        $roomId = $assignment->getRoom()?->getId();

        return null === $roomId ? '' : sprintf('%d|%s|%d', $roomId, $assignment->getDate()->format('Y-m-d'), $assignment->getSlotIndex());
    }

    /**
     * The base query for "in force": a line of the chosen option of an approved plan. Every read of the
     * effective timetable goes through here, so the definition lives in one place.
     *
     * @return QueryBuilder the builder, aliases {@code a} (assignment), {@code o} (option), {@code p} (plan)
     */
    private function inForceQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('a')
            ->join('a.option', 'o')
            ->join('o.plan', 'p')
            ->andWhere('p.chosenOption = o')
            ->andWhere('p.status = :approved')
            ->setParameter('approved', SpacePlanStatus::APPROVED);
    }
}
