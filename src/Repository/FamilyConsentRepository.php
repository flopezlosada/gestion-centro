<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FamilyConsent;
use App\Entity\FamilyPrivacyNotice;
use App\Entity\FamilyVolunteer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FamilyConsent>
 */
class FamilyConsentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FamilyConsent::class);
    }

    /**
     * Whether a family has accepted one version.
     *
     * @param FamilyVolunteer     $family the family
     * @param FamilyPrivacyNotice $notice the version
     *
     * @return bool true if it accepted that version
     */
    public function hasConsented(FamilyVolunteer $family, FamilyPrivacyNotice $notice): bool
    {
        return null !== $this->findOneBy(['family' => $family, 'notice' => $notice]);
    }

    /**
     * @param FamilyPrivacyNotice $notice the version
     *
     * @return int how many families have accepted it, for the admin screen
     */
    public function countFor(FamilyPrivacyNotice $notice): int
    {
        return $this->count(['notice' => $notice]);
    }
}
