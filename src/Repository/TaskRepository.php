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
        return $this->findBy(['schoolYear' => $schoolYear], ['dueDate' => 'ASC', 'id' => 'ASC']);
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
