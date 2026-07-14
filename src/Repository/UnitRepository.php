<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Unit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Unit>
 */
class UnitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Unit::class);
    }

    /**
     * Active departments, by name. The set a whole-school superior (dirección, jefatura de estudios)
     * may target when creating a task.
     *
     * @return Unit[] the active departments
     */
    public function findActiveDepartments(): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.active = true')
            ->andWhere('u.isDepartment = true')
            ->orderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
