<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Department;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Department>
 */
class DepartmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Department::class);
    }

    /**
     * Active departments, by name. The set a whole-school superior (dirección, jefatura de estudios)
     * may target when creating a task. Every unit is a department now, so this is simply the active
     * ones.
     *
     * @return Department[] the active departments
     */
    public function findActiveDepartments(): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.active = true')
            ->orderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
