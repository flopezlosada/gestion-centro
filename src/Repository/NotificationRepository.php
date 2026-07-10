<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /**
     * A person's notifications, newest first.
     *
     * @param User $user  the recipient
     * @param int  $limit maximum number to return
     *
     * @return Notification[] the notifications, newest first
     */
    public function findRecentFor(User $user, int $limit = 50): array
    {
        // Fetch-join the linked task: the inbox deep-links to it per row (avoids an N+1).
        return $this->createQueryBuilder('n')
            ->leftJoin('n.task', 'task')->addSelect('task')
            ->andWhere('n.recipient = :user')->setParameter('user', $user)
            ->orderBy('n.createdAt', 'DESC')->addOrderBy('n.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * How many unread notifications a person has (for the inbox badge).
     *
     * @param User $user the recipient
     *
     * @return int the unread count
     */
    public function countUnreadFor(User $user): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.recipient = :user')->setParameter('user', $user)
            ->andWhere('n.readAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
