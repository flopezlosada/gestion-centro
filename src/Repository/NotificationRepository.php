<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\Task;
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
     * The newest notice of a kind sent to a person about a task, or null if there is none. Used to hold
     * back a repeated nudge: the manual "Recordar" button and the nightly reminder engine share the kind
     * ({@see \App\Service\TaskReminderNotifier}) on purpose, so a person is never told twice in one day
     * about the same task through two different routes.
     *
     * @param User   $recipient who received it
     * @param Task   $task      the task it is about
     * @param string $kind      the machine kind (e.g. "task.reminder")
     *
     * @return Notification|null the newest matching notice, or null
     */
    public function findLatestAbout(User $recipient, Task $task, string $kind): ?Notification
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.recipient = :user')->setParameter('user', $recipient)
            ->andWhere('n.task = :task')->setParameter('task', $task)
            ->andWhere('n.kind = :kind')->setParameter('kind', $kind)
            ->orderBy('n.createdAt', 'DESC')->addOrderBy('n.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Deletes the notices that have expired, in one bulk statement: those already READ before
     * {@code $readBefore}, and those NEVER opened created before {@code $createdBefore}. The two
     * windows are different on purpose and the policy that picks them lives in
     * {@see \App\Service\NotificationPurger}; this only executes it.
     *
     * A bulk DQL DELETE rather than hydrating and removing: the table is the one thing in the system
     * that grows for ever, and there is nothing to cascade — a notice is a pointer, not a record (see
     * the purger for why there is no history). No dedicated index: the volume is a few thousand rows
     * per course and this runs once a day, so a scan of a small table is cheaper than an index that
     * every single insert would then have to maintain.
     *
     * @param \DateTimeImmutable $readBefore    cut-off for notices that were opened
     * @param \DateTimeImmutable $createdBefore cut-off for notices that never were
     *
     * @return int how many rows were deleted
     */
    public function deleteExpired(\DateTimeImmutable $readBefore, \DateTimeImmutable $createdBefore): int
    {
        return (int) $this->createQueryBuilder('n')
            ->delete()
            // Parenthesised explicitly: this must be (leído y viejo) OR (sin leer y muy viejo), and
            // leaving it to operator precedence is one refactor away from purging the whole inbox.
            ->where('(n.readAt IS NOT NULL AND n.readAt < :readBefore)')
            ->orWhere('(n.readAt IS NULL AND n.createdAt < :createdBefore)')
            ->setParameter('readBefore', $readBefore)
            ->setParameter('createdBefore', $createdBefore)
            ->getQuery()
            ->execute();
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
