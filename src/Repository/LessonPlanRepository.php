<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LessonPlan;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Every query is scoped to ONE teacher: a class plan is private to whoever wrote it.
 *
 * @extends ServiceEntityRepository<LessonPlan>
 */
class LessonPlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LessonPlan::class);
    }

    /**
     * The plan of one of the teacher's classes, if there is one.
     *
     * @param User               $teacher   the teacher
     * @param \DateTimeImmutable $date      the day
     * @param int                $slotIndex the period
     *
     * @return LessonPlan|null the plan
     */
    public function findForClass(User $teacher, \DateTimeImmutable $date, int $slotIndex): ?LessonPlan
    {
        return $this->findOneBy(['teacher' => $teacher, 'date' => $date->setTime(0, 0), 'slotIndex' => $slotIndex]);
    }

    /**
     * The plan of the class given just before this one to the same groups and subject — "where did I
     * leave it" with this group. Earlier days, or earlier periods of the same day.
     *
     * @param User               $teacher    the teacher
     * @param string             $subject    the subject
     * @param string             $groupNames the class's groups as shown
     * @param \DateTimeImmutable $date       the day of the class
     * @param int                $slotIndex  its period
     *
     * @return LessonPlan|null the previous plan with this group, or null
     */
    public function findPreviousFor(User $teacher, string $subject, string $groupNames, \DateTimeImmutable $date, int $slotIndex): ?LessonPlan
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.topic', 't')->addSelect('t')
            ->andWhere('p.teacher = :teacher')->setParameter('teacher', $teacher)
            ->andWhere('p.subject = :subject')->setParameter('subject', $subject)
            ->andWhere('p.groupNames = :groups')->setParameter('groups', $groupNames)
            ->andWhere('p.date < :date OR (p.date = :date AND p.slotIndex < :slot)')
            ->setParameter('date', $date->setTime(0, 0))
            ->setParameter('slot', $slotIndex)
            ->orderBy('p.date', 'DESC')->addOrderBy('p.slotIndex', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The teacher's plans over a range of days, keyed "Y-m-d|slot", so the calendar can mark which of
     * their classes are already planned in one query.
     *
     * @param User               $teacher the teacher
     * @param \DateTimeImmutable $from    the first day
     * @param \DateTimeImmutable $to      the last day
     *
     * @return array<string, LessonPlan> "Y-m-d|slot" → plan
     */
    public function findForTeacherBetween(User $teacher, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<LessonPlan> $plans */
        $plans = $this->createQueryBuilder('p')
            ->leftJoin('p.topic', 't')->addSelect('t')
            ->andWhere('p.teacher = :teacher')->setParameter('teacher', $teacher)
            ->andWhere('p.date BETWEEN :from AND :to')
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('to', $to->setTime(0, 0))
            ->getQuery()
            ->getResult();

        $byClass = [];
        foreach ($plans as $plan) {
            $byClass[$plan->getDate()->format('Y-m-d').'|'.$plan->getSlotIndex()] = $plan;
        }

        return $byClass;
    }
}
