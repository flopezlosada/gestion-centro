<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BreakZoneDemand;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BreakZoneDemand>
 */
class BreakZoneDemandRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BreakZoneDemand::class);
    }

    /**
     * Every exception, keyed by the cell it applies to ("zoneId:weekday:period").
     *
     * One read for the whole grid: resolving demand cell by cell would be fifty queries to draw one
     * screen, and the table only ever holds the handful of cells somebody has singled out.
     *
     * @return array<string, int> cell key → people needed
     */
    public function allByCell(): array
    {
        $byCell = [];
        foreach ($this->findAll() as $demand) {
            $key = $demand->getZone()->getId().':'.$demand->getWeekday()->value.':'.$demand->getPeriod()->value;
            $byCell[$key] = $demand->getRequiredTeachers();
        }

        return $byCell;
    }
}
