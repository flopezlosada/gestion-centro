<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PrivacyNotice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PrivacyNotice>
 */
class PrivacyNoticeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PrivacyNotice::class);
    }

    /**
     * The version in force: the latest published. Null while the centre has not published any, which
     * is what keeps the reading screen from blocking anybody with an empty text.
     *
     * @return PrivacyNotice|null the current version, or null if none has been published
     */
    public function findCurrent(): ?PrivacyNotice
    {
        return $this->createQueryBuilder('n')
            ->orderBy('n.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
