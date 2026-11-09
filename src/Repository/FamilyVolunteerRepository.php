<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FamilyVolunteer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FamilyVolunteer>
 */
class FamilyVolunteerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FamilyVolunteer::class);
    }

    /**
     * Every family account, by name: the staff screen lists them all.
     *
     * @return FamilyVolunteer[] the accounts
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('f')->orderBy('f.fullName', 'ASC')->getQuery()->getResult();
    }

    /**
     * The account signing in with the given address, compared the way it is stored.
     *
     * @param string $email the address typed or returned by the sign-in
     *
     * @return FamilyVolunteer|null the account, or null if there is none
     */
    public function findOneByEmail(string $email): ?FamilyVolunteer
    {
        return $this->findOneBy(['email' => strtolower(trim($email))]);
    }

    /**
     * Deletes, in one statement, every account created before that instant. Its signups, days available
     * and consents go with it through ON DELETE CASCADE in the database, so nothing is loaded first. A bulk
     * statement leaves no row in the activity trail: the cron run records how many went.
     *
     * @param \DateTimeImmutable $instant the first creation instant that is kept
     *
     * @return int how many accounts were deleted
     */
    public function deleteCreatedBefore(\DateTimeImmutable $instant): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->delete(FamilyVolunteer::class, 'f')
            ->where('f.createdAt < :instant')
            ->setParameter('instant', $instant, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->execute();
    }
}
