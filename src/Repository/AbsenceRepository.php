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
}
