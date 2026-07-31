<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Absence;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Absence>
 */
class AbsenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Absence::class);
    }

    /**
     * The absence of a teacher on a day, if already registered — the row the covers of that day hang
     * off. Used to reuse the same absence when more periods are added later, so the reason stays in
     * one place.
     *
     * @param User               $teacher the absent teacher
     * @param \DateTimeImmutable $date    the day
     *
     * @return Absence|null the existing absence, or null if none
     */
    public function findForTeacherAndDate(User $teacher, \DateTimeImmutable $date): ?Absence
    {
        return $this->findOneBy(['absentTeacher' => $teacher, 'date' => $date]);
    }

    /**
     * Who is away at a given period of a given day.
     *
     * This is the question the rota asks before handing anybody a guardia, and it used to be answered by
     * looking at the cover lines — which only exist for periods the teacher would have TAUGHT. A teacher
     * away during a period where they were on guardia produced no cover, so they did not count as away,
     * and the rota could hand them another group. Read from the absence itself, the answer is complete.
     *
     * The day's absences are a handful of rows, so the period is matched in PHP rather than with a JSON
     * predicate in DQL: it behaves the same on every engine and reads like the rule it implements.
     *
     * @param \DateTimeImmutable $date      the day
     * @param int                $slotIndex the period index within the day
     *
     * @return list<int> the ids of the teachers away at that period
     */
    public function absentTeacherIdsAt(\DateTimeImmutable $date, int $slotIndex): array
    {
        /** @var Absence[] $absences */
        $absences = $this->createQueryBuilder('a')
            ->andWhere('a.date = :date')
            ->setParameter('date', $date, 'date_immutable')
            ->getQuery()
            ->getResult();

        $ids = [];
        foreach ($absences as $absence) {
            if ($absence->coversSlot($slotIndex)) {
                $ids[] = (int) $absence->getAbsentTeacher()->getId();
            }
        }

        return $ids;
    }
}
