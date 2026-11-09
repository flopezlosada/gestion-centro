<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FamilyPrivacyNotice;
use App\Entity\PrivacyNotice;
use App\Entity\StaffPrivacyNotice;
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
     * The staff's version in force: the latest published. Null while the centre has not published any,
     * which is what keeps the reading screen from blocking anybody with an empty text.
     *
     * @return StaffPrivacyNotice|null the current version, or null if none has been published
     */
    public function findCurrentForStaff(): ?StaffPrivacyNotice
    {
        return $this->latestOf(StaffPrivacyNotice::class);
    }

    /**
     * The families' version in force: the latest published. Null while there is none, and then no family
     * can use its area — they are asked for consent, and there is nothing to consent to.
     *
     * @return FamilyPrivacyNotice|null the current version, or null if none has been published
     */
    public function findCurrentForFamilies(): ?FamilyPrivacyNotice
    {
        return $this->latestOf(FamilyPrivacyNotice::class);
    }

    /**
     * @template T of PrivacyNotice
     *
     * @param class-string<T> $audience the version type of one audience
     *
     * @return T|null its latest published version, or null if there is none
     */
    private function latestOf(string $audience): ?PrivacyNotice
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('n')
            ->from($audience, 'n')
            ->orderBy('n.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
