<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FamilySignup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FamilySignup>
 */
class FamilySignupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FamilySignup::class);
    }

    /**
     * Deletes, in one statement, every signup to a session held before that day. Nothing hangs from a
     * signup and it is not audited, so there is nothing to load first.
     *
     * @param \DateTimeImmutable $day the first session day that is kept
     *
     * @return int how many signups were deleted
     */
    public function deleteHeldBefore(\DateTimeImmutable $day): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->delete(FamilySignup::class, 's')
            ->where('s.sessionDate < :day')
            ->setParameter('day', $day, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->execute();
    }
}
