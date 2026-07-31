<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Material;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Material>
 */
class MaterialRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Material::class);
    }

    /**
     * Every piece of material, alphabetically: the admin screen shows them all, retired ones included.
     *
     * @return Material[] the catalogue
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('m')->orderBy('m.name', 'ASC')->getQuery()->getResult();
    }

    /**
     * The material offered when booking: only what is in use.
     *
     * @return Material[] the active material, alphabetically
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.active = true')
            ->orderBy('m.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
