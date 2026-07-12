<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Role;
use App\Entity\Unit;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Finds an active user by e-mail, or null if there is no active user with that address.
     * Only registered (allow-listed) users can sign in, so an unknown e-mail returns null.
     *
     * @param string $email the e-mail address
     *
     * @return User|null the matching active user, or null
     */
    public function findActiveByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => strtolower(trim($email)), 'active' => true]);
    }

    /**
     * The active users who hold the given role — the people behind a responsibility, who receive
     * its obligation reminders (several co-responsibles are allowed).
     *
     * @param Role $role the responsibility
     *
     * @return User[] the active holders of the role
     */
    public function findActiveByRole(Role $role): array
    {
        return $this->createQueryBuilder('u')
            ->join('u.assignedRoles', 'r')
            ->where('r = :role')
            ->andWhere('u.active = true')
            ->setParameter('role', $role)
            ->getQuery()
            ->getResult();
    }

    /**
     * Active users belonging to any of the given units, by full name. Used to build the "assign to"
     * choices, scoped to a creator's own unit and the units below it.
     *
     * @param list<Unit> $units the units to look in
     *
     * @return User[] the active users in those units
     */
    public function findActiveInUnits(array $units): array
    {
        if ([] === $units) {
            return [];
        }

        return $this->createQueryBuilder('u')
            ->andWhere('u.unit IN (:units)')
            ->andWhere('u.active = true')
            ->setParameter('units', $units)
            ->orderBy('u.fullName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Everyone who belongs to a unit, by full name. Used by the department detail.
     *
     * @param Unit $unit the unit (department)
     *
     * @return User[] the people in that unit
     */
    public function findByUnit(Unit $unit): array
    {
        return $this->findBy(['unit' => $unit], ['fullName' => 'ASC']);
    }
}

