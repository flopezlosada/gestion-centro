<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\FamilyAvailabilitySpan;
use App\Entity\FamilyAvailability;
use App\Entity\FamilyVolunteer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FamilyAvailability>
 */
class FamilyAvailabilityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FamilyAvailability::class);
    }

    /**
     * What one family has said, keyed by day of the week, so its form can be filled and saved day by day.
     *
     * @param FamilyVolunteer $family the family
     *
     * @return array<int, FamilyAvailability> its spans, keyed by {@see \App\Enum\Weekday} value
     */
    public function findOfFamilyByWeekday(FamilyVolunteer $family): array
    {
        $spans = $this->findBy(['family' => $family]);

        return array_combine(array_map(static fn (FamilyAvailability $s): int => $s->getWeekday()->value, $spans), $spans);
    }

    /**
     * Every family's spans, grouped by day of the week and earliest first: the staff's overview of when
     * families can come. Handed out without the family ({@see FamilyAvailabilitySpan}): the overview
     * counts, it does not say who.
     *
     * @return array<int, list<FamilyAvailabilitySpan>> the spans, keyed by {@see \App\Enum\Weekday} value
     */
    public function findAllByWeekday(): array
    {
        $spans = $this->createQueryBuilder('a')
            ->orderBy('a.weekday', 'ASC')
            ->addOrderBy('a.from', 'ASC')
            ->addOrderBy('a.until', 'ASC')
            ->getQuery()
            ->getResult();

        return array_reduce($spans, static function (array $byDay, FamilyAvailability $span): array {
            $byDay[$span->getWeekday()->value][] = FamilyAvailabilitySpan::of($span);

            return $byDay;
        }, []);
    }
}
