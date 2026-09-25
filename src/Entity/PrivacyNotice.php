<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Repository\PrivacyNoticeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One published version of the data-protection information the staff must read (art. 13 GDPR).
 *
 * Immutable on purpose: every acknowledgement points at the exact text that person read, so a row is
 * never edited — a change, even a typo, is published as a new version and everyone reads it again. The
 * current version is simply the latest one ({@see PrivacyNoticeRepository::findCurrent()}).
 *
 * It is information, not consent: a public school processes its staff's data under a legal obligation
 * and a public-interest task, so there is nothing to "accept" — only proof that people were informed.
 */
#[ORM\Entity(repositoryClass: PrivacyNoticeRepository::class)]
#[ORM\Table(name: 'privacy_notice')]
class PrivacyNotice implements Auditable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The short first layer (who, what for, on what basis, rights), shown on the reading screen. */
    #[ORM\Column(type: Types::TEXT)]
    private string $summary;

    /** The full information, for whoever wants every detail. */
    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $publishedAt;

    /** Who published it; kept null if that account is ever deleted, the text itself must survive. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'published_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $publishedBy;

    /**
     * @param string             $summary     the short first layer
     * @param string             $body        the full information
     * @param User               $publishedBy who publishes it
     * @param \DateTimeImmutable $publishedAt when it takes effect
     */
    public function __construct(string $summary, string $body, User $publishedBy, \DateTimeImmutable $publishedAt)
    {
        $this->summary = trim($summary);
        $this->body = trim($body);
        $this->publishedBy = $publishedBy;
        $this->publishedAt = $publishedAt;
    }

    /**
     * @return int|null the id, null until persisted
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return string the short first layer
     */
    public function getSummary(): string
    {
        return $this->summary;
    }

    /**
     * @return string the full information
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * @return \DateTimeImmutable when this version took effect
     */
    public function getPublishedAt(): \DateTimeImmutable
    {
        return $this->publishedAt;
    }

    /**
     * @return User|null who published it, null if that account no longer exists
     */
    public function getPublishedBy(): ?User
    {
        return $this->publishedBy;
    }
}
