<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A family that has said it will come to an activity.
 *
 * Only {@see FamilyActivity::signUp()} creates one, so the rules (not started, not full, once per family)
 * hold for every signup stored. It goes with either end: removing the activity or the family's account
 * removes it too, so a removed account leaves no trace of where that person went.
 *
 * Deliberately NOT {@see \App\Contract\Auditable}: the activity trail outlives the account, and a row
 * per signup ("familia #12 came to this activity") would keep exactly that trace after it is removed.
 */
#[ORM\Entity]
#[ORM\Table(name: 'family_signup')]
#[ORM\UniqueConstraint(name: 'uniq_family_signup', columns: ['activity_id', 'family_id'])]
class FamilySignup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: FamilyActivity::class, inversedBy: 'signups')]
    #[ORM\JoinColumn(name: 'activity_id', nullable: false, onDelete: 'CASCADE')]
    private FamilyActivity $activity;

    #[ORM\ManyToOne(targetEntity: FamilyVolunteer::class)]
    #[ORM\JoinColumn(name: 'family_id', nullable: false, onDelete: 'CASCADE')]
    private FamilyVolunteer $family;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @internal use {@see FamilyActivity::signUp()}, which checks the rules first
     *
     * @param FamilyActivity     $activity the activity
     * @param FamilyVolunteer    $family   who comes
     * @param \DateTimeImmutable $now      when they signed up
     */
    public function __construct(FamilyActivity $activity, FamilyVolunteer $family, \DateTimeImmutable $now)
    {
        $this->activity = $activity;
        $this->family = $family;
        $this->createdAt = $now;
    }

    /**
     * @return int|null the id, null until persisted
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return FamilyActivity the activity
     */
    public function getActivity(): FamilyActivity
    {
        return $this->activity;
    }

    /**
     * @return FamilyVolunteer who comes
     */
    public function getFamily(): FamilyVolunteer
    {
        return $this->family;
    }

    /**
     * @return \DateTimeImmutable when they signed up
     */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
