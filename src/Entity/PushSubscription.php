<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PushSubscriptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single browser's Web Push subscription for a user: the push-service endpoint plus the two keys
 * the browser handed us ({@code p256dh}, {@code auth}) to encrypt payloads for it. One person can
 * hold several (laptop, phone, staff-room PC…), so a user has many of these.
 *
 * The endpoint is the natural key (a browser re-subscribing sends the same one), hence unique: a
 * re-subscribe upserts instead of piling duplicates. Deliberately NOT audited: high-volume, per-device
 * and ephemeral, like {@see Notification}. Rows are pruned automatically when the push service reports
 * the subscription gone (404/410) — see {@see \App\Service\WebPushSender}.
 */
#[ORM\Entity(repositoryClass: PushSubscriptionRepository::class)]
#[ORM\Table(name: 'push_subscription')]
#[ORM\UniqueConstraint(name: 'uniq_push_subscription_endpoint', columns: ['endpoint'])]
#[ORM\Index(name: 'idx_push_subscription_user', columns: ['user_id'])]
class PushSubscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** The push-service URL to POST the encrypted notification to (browser + service specific). */
    #[ORM\Column(length: 512)]
    private string $endpoint;

    /** The client's public key (Base64URL), used to encrypt the payload for this browser. */
    #[ORM\Column(length: 255)]
    private string $p256dh;

    /** The client's auth secret (Base64URL), part of the payload encryption. */
    #[ORM\Column(length: 255)]
    private string $auth;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $endpoint, string $p256dh, string $auth)
    {
        $this->user = $user;
        $this->endpoint = $endpoint;
        $this->p256dh = $p256dh;
        $this->auth = $auth;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function getP256dh(): string
    {
        return $this->p256dh;
    }

    public function getAuth(): string
    {
        return $this->auth;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
