<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PushSubscription;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PushSubscription>
 */
class PushSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PushSubscription::class);
    }

    /**
     * Every browser subscription registered by a user (all their devices).
     *
     * @param User $user the owner
     *
     * @return PushSubscription[] the user's subscriptions
     */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user]);
    }

    /**
     * The subscription for a given push endpoint, if we already hold it. Used to upsert on
     * re-subscribe instead of duplicating (the endpoint is the browser's stable identity).
     *
     * @param string $endpoint the push-service endpoint
     *
     * @return PushSubscription|null the existing subscription, or null
     */
    public function findOneByEndpoint(string $endpoint): ?PushSubscription
    {
        return $this->findOneBy(['endpoint' => $endpoint]);
    }
}
