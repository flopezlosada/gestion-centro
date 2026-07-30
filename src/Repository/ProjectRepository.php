<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Project;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Project>
 */
class ProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Project::class);
    }

    /**
     * Every project, by name, for the admin list.
     *
     * @return list<Project> the projects in display order
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The live projects, with their members already loaded. For an admin convening a meeting: they may
     * pick any project, and the screen then reads each one's people — without the join that is a query
     * per project.
     *
     * @return list<Project> the live projects, by name
     */
    public function findActiveWithMembers(): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.members', 'm')
            ->addSelect('m')
            ->andWhere('p.active = true')
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The live projects a person coordinates, with their members already loaded (the convening form
     * needs them to prefill the attendees, so fetching them here avoids a query per project). Finished
     * projects are left out: they are history, nothing is convened for them any more.
     *
     * @param User $coordinator the person
     *
     * @return list<Project> the projects they coordinate, by name
     */
    public function findActiveCoordinatedBy(User $coordinator): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.members', 'm')
            ->addSelect('m')
            ->andWhere('p.coordinator = :coordinator')
            ->andWhere('p.active = true')
            ->setParameter('coordinator', $coordinator)
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
