<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PrivacyNotice;
use App\Entity\PrivacyNoticeAck;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PrivacyNoticeAck>
 */
class PrivacyNoticeAckRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PrivacyNoticeAck::class);
    }

    /**
     * Whether a person has read one version.
     *
     * @param User          $user   the person
     * @param PrivacyNotice $notice the version
     *
     * @return bool true if they acknowledged that version
     */
    public function hasAcknowledged(User $user, PrivacyNotice $notice): bool
    {
        return null !== $this->findOneBy(['user' => $user, 'notice' => $notice]);
    }

    /**
     * How many ACTIVE people have read one version, for the admin screen. Inactive accounts cannot sign
     * in, so they could never read it and would only make the count look worse than it is.
     *
     * @param PrivacyNotice $notice the version
     *
     * @return int the number of active people who acknowledged it
     */
    public function countActiveReaders(PrivacyNotice $notice): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->join('a.user', 'u')
            ->andWhere('a.notice = :notice')
            ->andWhere('u.active = true')
            ->setParameter('notice', $notice)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
