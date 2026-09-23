<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MeetingGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MeetingGroup>
 */
class MeetingGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MeetingGroup::class);
    }

    /**
     * Every group, alphabetically: the admin screen shows them all, retired ones included.
     *
     * @return MeetingGroup[] the groups
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('g')->orderBy('g.name', 'ASC')->getQuery()->getResult();
    }

    /**
     * The groups offered as a convocation shortcut: only the ones in use.
     *
     * @return MeetingGroup[] the active groups, alphabetically
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('g')
            ->andWhere('g.active = true')
            ->orderBy('g.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
