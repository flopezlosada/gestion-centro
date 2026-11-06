<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Task;
use App\Entity\TaskReminderSchedule;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TaskReminderSchedule>
 */
class TaskReminderScheduleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaskReminderSchedule::class);
    }

    /**
     * The schedule one person chose for one task, or null if they kept the default.
     *
     * @param Task $task the task
     * @param User $user the person
     */
    public function findOneFor(Task $task, User $user): ?TaskReminderSchedule
    {
        return $this->findOneBy(['task' => $task, 'user' => $user]);
    }

    /**
     * Every schedule chosen for the given tasks, in ONE query, keyed so the nightly sweep can look each
     * (task, person) up without a query per task.
     *
     * @param list<Task> $tasks the tasks the sweep is looking at
     *
     * @return array<string, TaskReminderSchedule> keyed by {@see key()}
     */
    public function findForTasks(array $tasks): array
    {
        if ([] === $tasks) {
            return [];
        }

        /** @var list<TaskReminderSchedule> $schedules */
        $schedules = $this->createQueryBuilder('s')
            ->andWhere('s.task IN (:tasks)')
            ->setParameter('tasks', $tasks)
            ->getQuery()
            ->getResult();

        $keyed = [];
        foreach ($schedules as $schedule) {
            $keyed[self::key($schedule->getTask(), $schedule->getUser())] = $schedule;
        }

        return $keyed;
    }

    /**
     * The lookup key of a (task, person) pair in {@see findForTasks()}.
     *
     * @param Task $task the task
     * @param User $user the person
     *
     * @return string the key
     */
    public static function key(Task $task, User $user): string
    {
        return $task->getId().'-'.$user->getId();
    }
}
