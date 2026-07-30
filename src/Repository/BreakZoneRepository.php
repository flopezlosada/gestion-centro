<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BreakZone;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BreakZone>
 */
class BreakZoneRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BreakZone::class);
    }

    /**
     * The zones in use, in the centre's own order — what the rota grid shows and what a picker offers.
     * Archived zones are left out: they exist only so old rotas keep naming them.
     *
     * @return BreakZone[] the active zones, in display order
     */
    public function findActiveOrdered(): array
    {
        return $this->orderedQuery()
            ->andWhere('z.archived = false')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every zone, archived ones included — the management screen, where archiving happens.
     *
     * @return BreakZone[] all zones, in display order
     */
    public function findAllOrdered(): array
    {
        return $this->orderedQuery()->getQuery()->getResult();
    }

    /**
     * The largest sort order in use, so a new zone lands at the end of the list instead of jumping to
     * the front. Zero when there are no zones yet.
     *
     * @return int the highest sort order, or 0
     */
    public function maxSortOrder(): int
    {
        return (int) $this->createQueryBuilder('z')
            ->select('COALESCE(MAX(z.sortOrder), 0)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Base query ordering zones the way the centre reads them: its own order first, name as tie-break.
     *
     * @return \Doctrine\ORM\QueryBuilder the ordered query
     */
    private function orderedQuery(): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('z')
            ->orderBy('z.sortOrder', 'ASC')
            ->addOrderBy('z.name', 'ASC');
    }
}
