<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Task;
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
