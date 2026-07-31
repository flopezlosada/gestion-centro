<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Task;
use App\Entity\TaskComment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TaskComment>
 */
class TaskCommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaskComment::class);
    }

    /**
     * The thread of a task, oldest first — it is a conversation, and a conversation is read in the order
     * it happened.
     *
     * @param Task $task the task
     *
     * @return TaskComment[] the comments, oldest first
     */
    public function findThreadFor(Task $task): array
    {
        // Fetch-join the author: every row prints their name (avoids an N+1 over the thread).
        return $this->createQueryBuilder('c')
            ->leftJoin('c.author', 'author')->addSelect('author')
            ->andWhere('c.task = :task')->setParameter('task', $task)
            ->orderBy('c.createdAt', 'ASC')->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
