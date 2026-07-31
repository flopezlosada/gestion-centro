<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Role;
use App\Enum\Area;
use App\Enum\PermissionLevel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Role>
 */
class RoleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Role::class);
    }

    /**
     * The roles that grant WRITE over an area — plus the superuser roles, which grant everything without
     * appearing in the matrix ({@see Role::isAdmin()}). Used to find the people who have to hear about
     * something that concerns a whole area, e.g. an incident on a guardia reaching its coordination.
     *
     * Filtered in PHP on purpose: the matrix is a JSON column, and a portable DQL query over it does not
     * exist (MariaDB and MySQL disagree on the functions). The role catalogue is a handful of rows, so
     * loading it is cheaper than the SQL it would take to avoid loading it.
     *
     * @param Area $area the area to look for
     *
     * @return list<Role> the roles whose holders may act on that area
     */
    public function findWritersOf(Area $area): array
    {
        return array_values(array_filter(
            $this->findAll(),
            static fn (Role $role): bool => $role->isAdmin() || PermissionLevel::WRITE === $role->getLevel($area),
        ));
    }

    /**
     * Returns all roles ordered by name.
     *
     * @return Role[]
     */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }
}
