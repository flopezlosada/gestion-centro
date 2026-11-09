<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FamilyConsentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The proof that a family accepted one version of its data-protection information. One row per family
 * and version; a new version asks again and the older rows stay.
 *
 * It goes with the account (CASCADE): once the family leaves, there is nothing left to prove consent
 * for. Not audited, like the signups: the activity trail outlives the account.
 */
#[ORM\Entity(repositoryClass: FamilyConsentRepository::class)]
#[ORM\Table(name: 'family_consent')]
#[ORM\UniqueConstraint(name: 'uniq_family_consent', columns: ['family_id', 'notice_id'])]
class FamilyConsent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: FamilyVolunteer::class)]
    #[ORM\JoinColumn(name: 'family_id', nullable: false, onDelete: 'CASCADE')]
    private FamilyVolunteer $family;

    /** RESTRICT: a version somebody accepted cannot be deleted, or the proof would point at nothing. */
    #[ORM\ManyToOne(targetEntity: FamilyPrivacyNotice::class)]
    #[ORM\JoinColumn(name: 'notice_id', nullable: false, onDelete: 'RESTRICT')]
    private FamilyPrivacyNotice $notice;

    #[ORM\Column(name: 'consented_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $consentedAt;

    /**
     * @param FamilyVolunteer     $family      who accepted
     * @param FamilyPrivacyNotice $notice      the version they accepted
     * @param \DateTimeImmutable  $consentedAt when
     */
    public function __construct(FamilyVolunteer $family, FamilyPrivacyNotice $notice, \DateTimeImmutable $consentedAt)
    {
        $this->family = $family;
        $this->notice = $notice;
        $this->consentedAt = $consentedAt;
    }

    /**
     * @return int|null the id, null until persisted
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return FamilyVolunteer who accepted
     */
    public function getFamily(): FamilyVolunteer
    {
        return $this->family;
    }

    /**
     * @return FamilyPrivacyNotice the version they accepted
     */
    public function getNotice(): FamilyPrivacyNotice
    {
        return $this->notice;
    }

    /**
     * @return \DateTimeImmutable when they accepted it
     */
    public function getConsentedAt(): \DateTimeImmutable
    {
        return $this->consentedAt;
    }
}
