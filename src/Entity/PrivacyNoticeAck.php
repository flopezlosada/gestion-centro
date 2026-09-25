<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PrivacyNoticeAckRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The proof that a person was shown one version of the data-protection information and said they had
 * read it. One row per person and version; older rows stay, so the history of what each person read is
 * never lost when a new version comes out.
 */
#[ORM\Entity(repositoryClass: PrivacyNoticeAckRepository::class)]
#[ORM\Table(name: 'privacy_notice_ack')]
#[ORM\UniqueConstraint(name: 'uniq_privacy_notice_ack', columns: ['user_id', 'notice_id'])]
class PrivacyNoticeAck
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** RESTRICT: a version somebody has read cannot be deleted, or the proof would point at nothing. */
    #[ORM\ManyToOne(targetEntity: PrivacyNotice::class)]
    #[ORM\JoinColumn(name: 'notice_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private PrivacyNotice $notice;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $acknowledgedAt;

    /**
     * @param User               $user           who read it
     * @param PrivacyNotice      $notice         the version they read
     * @param \DateTimeImmutable $acknowledgedAt when they said so
     */
    public function __construct(User $user, PrivacyNotice $notice, \DateTimeImmutable $acknowledgedAt)
    {
        $this->user = $user;
        $this->notice = $notice;
        $this->acknowledgedAt = $acknowledgedAt;
    }

    /**
     * @return int|null the id, null until persisted
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return User who read it
     */
    public function getUser(): User
    {
        return $this->user;
    }

    /**
     * @return PrivacyNotice the version they read
     */
    public function getNotice(): PrivacyNotice
    {
        return $this->notice;
    }

    /**
     * @return \DateTimeImmutable when they said they had read it
     */
    public function getAcknowledgedAt(): \DateTimeImmutable
    {
        return $this->acknowledgedAt;
    }
}
