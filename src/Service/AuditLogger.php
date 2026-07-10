<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Central, append-only writer for the activity trail. Every relevant action in the application
 * funnels through here so nothing slips past the {@see AuditLog}.
 */
class AuditLogger
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
    ) {
    }

    /**
     * Records one event. The actor defaults to the currently authenticated user, but can be
     * passed explicitly (e.g. during the login event, before the token is in the session).
     *
     * It persists AND flushes so the entry is written even from event subscribers that have no
     * other flush. Convention: call it AFTER the business flush, so it never pushes other
     * half-finished Unit of Work changes to the database.
     *
     * @param string      $action      machine-readable event name (e.g. "consumption.created")
     * @param string|null $subjectType affected entity type, if any
     * @param string|null $subjectId   affected entity id, if any
     * @param string|null $summary     human-readable Spanish description
     * @param string|null $actor       overrides the current user identifier when provided
     */
    public function log(string $action, ?string $subjectType = null, ?string $subjectId = null, ?string $summary = null, ?string $actor = null): void
    {
        $actor ??= $this->security->getUser()?->getUserIdentifier();

        $this->entityManager->persist(new AuditLog($action, $actor, $subjectType, $subjectId, $summary));
        $this->entityManager->flush();
    }
}
