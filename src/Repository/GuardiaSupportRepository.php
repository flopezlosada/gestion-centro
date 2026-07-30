<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GuardiaSupport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GuardiaSupport>
 */
class GuardiaSupportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GuardiaSupport::class);
    }

    /**
     * The colleagues signed up by hand for a date and period, teachers eager-loaded — the extra band the
     * assignment engine falls back on before doubling anybody up, and the list the parte shows so a
     * coordinator can see (and undo) what somebody else arranged.
     *
     * @param \DateTimeImmutable $date      the day
     * @param int                $slotIndex the period index within the day
     *
     * @return GuardiaSupport[] the support entries, teacher name ascending
     */
    public function findForSlot(\DateTimeImmutable $date, int $slotIndex): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('t')
            ->join('s.teacher', 't')
            ->andWhere('s.date = :date')
            ->andWhere('s.slotIndex = :slot')
            ->setParameter('date', $date, 'date_immutable')
            ->setParameter('slot', $slotIndex)
            ->orderBy('t.fullName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every colleague signed up by hand on a date, whatever the period — the day's arrangements, so the
     * whole parte can be read without a query per period.
     *
     * @param \DateTimeImmutable $date the day
     *
     * @return GuardiaSupport[] the support entries of that day, earliest period first
     */
    public function findForDate(\DateTimeImmutable $date): array
    {
        return $this->createQueryBuilder('s')
            ->addSelect('t')
            ->join('s.teacher', 't')
            ->andWhere('s.date = :date')
            ->setParameter('date', $date, 'date_immutable')
            ->orderBy('s.slotIndex', 'ASC')
            ->addOrderBy('t.fullName', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
