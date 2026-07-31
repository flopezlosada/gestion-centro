<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MeetingType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MeetingType>
 */
class MeetingTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MeetingType::class);
    }

    /**
     * Every kind, alphabetically: the admin screen shows them all, retired ones included.
     *
     * @return MeetingType[] the kinds
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('t')->orderBy('t.name', 'ASC')->getQuery()->getResult();
    }

    /**
     * The kinds offered when convening: only the ones in use.
     *
     * @return MeetingType[] the active kinds, alphabetically
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.active = true')
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
