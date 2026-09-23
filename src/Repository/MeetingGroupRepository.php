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
     * Every group, alphabetically: the admin screen shows them all, retired ones included, with their
     * convener and members fetch-joined because the list shows both for every row.
     *
     * @return MeetingGroup[] the groups
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('g')
            ->addSelect('c', 'm')
            ->leftJoin('g.convener', 'c')
            ->leftJoin('g.members', 'm')
            ->orderBy('g.name', 'ASC')
            ->getQuery()
            ->getResult();
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

    /**
     * The groups whose weekly meetings are generated: active, repeating and with somebody to convene
     * them (the same rule as {@see MeetingGroup::generatesMeetings()}). Members and convener are
     * fetch-joined, since every generated meeting copies them.
     *
     * @return MeetingGroup[] the groups
     */
    public function findGenerating(): array
    {
        return $this->createQueryBuilder('g')
            ->addSelect('c', 'm')
            ->join('g.convener', 'c')
            ->leftJoin('g.members', 'm')
            ->andWhere('g.active = true')
            ->andWhere('g.weekday IS NOT NULL')
            ->andWhere('g.slotIndex IS NOT NULL')
            ->orderBy('g.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
