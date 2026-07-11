<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Role;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Task>
 */
class TaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Task::class);
    }

    /**
     * Tasks of a course, earliest deadline first.
     *
     * @param string $schoolYear the course in "YYYY-YYYY" form
     *
     * @return Task[] the tasks of that course
     */
    public function findBySchoolYear(string $schoolYear): array
    {
        // Fetch-join the associations shown in the list/plan to avoid an N+1 per row.
        return $this->createQueryBuilder('t')
            ->leftJoin('t.unit', 'unit')->addSelect('unit')
            ->leftJoin('t.assignedUser', 'assignedUser')->addSelect('assignedUser')
            ->leftJoin('t.assignedRole', 'assignedRole')->addSelect('assignedRole')
            ->andWhere('t.schoolYear = :year')
            ->setParameter('year', $schoolYear)
            ->orderBy('t.dueDate', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Tasks whose deadline falls within an inclusive date range, earliest deadline first. Used by
     * the monthly calendar to fill a visible month grid.
     *
     * @param \DateTimeImmutable $from the first day of the range (inclusive)
     * @param \DateTimeImmutable $to   the last day of the range (inclusive)
     *
     * @return Task[] the tasks due within the range
     */
    public function findDueBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        // Fetch-join the associations shown on each day cell to avoid an N+1 per row.
        return $this->createQueryBuilder('t')
            ->leftJoin('t.unit', 'unit')->addSelect('unit')
            ->leftJoin('t.assignedUser', 'assignedUser')->addSelect('assignedUser')
            ->leftJoin('t.assignedRole', 'assignedRole')->addSelect('assignedRole')
            ->andWhere('t.dueDate BETWEEN :from AND :to')
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->orderBy('t.dueDate', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * A person's agenda for a course: the tasks assigned to them directly or to any of their roles,
     * earliest deadline first.
     *
     * @param User   $user       the person
     * @param string $schoolYear the course in "YYYY-YYYY" form
     *
     * @return Task[] the person's tasks that course
     */
    public function findAgendaFor(User $user, string $schoolYear): array
    {
        $roleIds = array_values(array_filter(
            $user->getAssignedRoles()->map(static fn (Role $role): ?int => $role->getId())->toArray(),
        ));

        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.schoolYear = :year')
            ->setParameter('year', $schoolYear)
            ->orderBy('t.dueDate', 'ASC')
            ->addOrderBy('t.id', 'ASC');

        if ([] === $roleIds) {
            $qb->andWhere('t.assignedUser = :user')->setParameter('user', $user);
        } else {
            $qb->andWhere('t.assignedUser = :user OR IDENTITY(t.assignedRole) IN (:roles)')
                ->setParameter('user', $user)
                ->setParameter('roles', $roleIds);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Open (not yet validated) tasks whose deadline falls on a given day. Used by the reminder
     * engine to find what is due in N days.
     *
     * @param \DateTimeImmutable $day       the deadline day to match
     * @param list<string>       $openPlaces the statuses considered still open
     *
     * @return Task[] the matching open tasks
     */
    public function findOpenDueOn(\DateTimeImmutable $day, array $openPlaces): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.dueDate = :day')
            ->andWhere('t.status IN (:open)')
            ->setParameter('day', $day->format('Y-m-d'))
            ->setParameter('open', $openPlaces)
            ->orderBy('t.dueDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
