<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Role;
use App\Entity\Department;
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
     * Everyone, by full name, with their roles already loaded. For screens that ask each user what
     * they are allowed to do (which walks the role collection): without the join that is one extra
     * query per person.
     *
     * @return User[] every user, ordered by name
     */
    public function findAllWithRoles(): array
    {
        return $this->createQueryBuilder('u')
            ->leftJoin('u.assignedRoles', 'r')
            ->addSelect('r')
            ->orderBy('u.fullName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every active person, by full name. The widest people list an admin may pick from (e.g. who to
     * convene to a meeting); the scoped screens use {@see findActiveInUnits()} instead. The department comes
     * in the same query: the lists show it under each name, and lazily it was one query per person.
     *
     * @return User[] the active users, ordered by name
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('u')
            ->leftJoin('u.unit', 'unit')
            ->addSelect('unit')
            ->andWhere('u.active = true')
            ->orderBy('u.fullName', 'ASC')
            ->getQuery()
            ->getResult();
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
     * Active users who hold at least one role with a chain-of-command rank (dirección, jefatura de
     * estudios, adjunto, jefe de departamento). The set of potential superiors, from which
     * {@see \App\Service\OrganizationHierarchy::managersAbove()} picks those above a given task. Small
     * by nature (only the leadership), so resolving each user's full role set afterwards is cheap.
     *
     * @return User[] the active holders of any ranked role
     */
    public function findWithHierarchyRank(): array
    {
        return $this->createQueryBuilder('u')
            ->join('u.assignedRoles', 'r')
            ->where('r.hierarchyLevel IS NOT NULL')
            ->andWhere('u.active = true')
            ->distinct()
            ->getQuery()
            ->getResult();
    }

    /**
     * The equipo directivo: active users holding a centre-wide ranked role (dirección, jefatura de
     * estudios, adjunta). Ranked-but-per-department roles are excluded on purpose — a jefe de
     * departamento commands their own department, not the centre — which is what separates this from
     * {@see findWithHierarchyRank()}.
     *
     * These are the people alerted when a recreo is left unwatched: the centre's rule is that a break
     * duty is not re-covered, so somebody with authority over the whole centre has to look for a
     * volunteer.
     *
     * @return User[] the leadership team, by full name
     */
    public function findLeadershipTeam(): array
    {
        return $this->createQueryBuilder('u')
            ->join('u.assignedRoles', 'r')
            ->where('r.hierarchyLevel IS NOT NULL')
            ->andWhere('r.perDepartment = false')
            ->andWhere('u.active = true')
            ->orderBy('u.fullName', 'ASC')
            ->distinct()
            ->getQuery()
            ->getResult();
    }

    /**
     * Active users belonging to any of the given units, by full name. Used to build the "assign to"
     * choices, scoped to a creator's own unit and the units below it.
     *
     * @param list<Department> $units the units to look in
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
     * @param Department $unit the unit (department)
     *
     * @return User[] the people in that unit
     */
    public function findByUnit(Department $unit): array
    {
        return $this->findBy(['unit' => $unit], ['fullName' => 'ASC']);
    }

    /**
     * Everyone NOT already in the given unit (including people with no unit), by full name. Used to
     * offer candidates to add to a department.
     *
     * @param Department $unit the unit (department) to exclude
     *
     * @return User[] the people who could be added to it
     */
    public function findNotInUnit(Department $unit): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.unit IS NULL OR u.unit != :unit')
            ->setParameter('unit', $unit)
            ->orderBy('u.fullName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Gives each Peñalara code to the person who holds that post now, taking it from whoever had it.
     *
     * Peñalara hands the code of someone on leave to their substitute, so an import can move a code from
     * one person to another. The column is unique, and a single flush that both sets the newcomer's code
     * and clears the old holder's may run the two UPDATEs in either order, so the codes are first freed
     * in one statement and only then written. The bulk UPDATE skips the identity map, so the old holders
     * still loaded in memory are cleared too: otherwise a later flush would see no change on them and
     * the in-memory value would lie about the row.
     *
     * @param array<string|int, User> $codes Peñalara code → the person who must hold it
     */
    public function reassignPenalaraCodes(array $codes): void
    {
        if ([] === $codes) {
            return;
        }

        // PHP turns numeric string keys into ints; the column is a string.
        $values = array_map('strval', array_keys($codes));
        $em = $this->getEntityManager();
        $em->wrapInTransaction(function () use ($em, $codes, $values): void {
            foreach ($this->findBy(['penalaraCode' => $values]) as $holder) {
                $holder->setPenalaraCode(null);
            }
            $this->createQueryBuilder('u')
                ->update()
                ->set('u.penalaraCode', 'NULL')
                ->where('u.penalaraCode IN (:codes)')
                ->setParameter('codes', $values)
                ->getQuery()
                ->execute();

            foreach ($codes as $code => $user) {
                $user->setPenalaraCode((string) $code);
            }
            $em->flush();
        });
    }
}
