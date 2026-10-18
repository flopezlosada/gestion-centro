<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FamilyVolunteer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
}
