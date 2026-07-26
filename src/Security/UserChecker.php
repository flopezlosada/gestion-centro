<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Applies the {@see AccessGate} at authentication: a deactivated account, or one without early
 * access while sign-in is restricted, never gets a session.
 *
 * This runs only when someone authenticates. Symfony's UserCheckerListener is subscribed to
 * CheckPassportEvent alone, and ContextListener::refreshUser() calls no user checker, so an
 * already-open session is NOT re-checked here; that is
 * {@see \App\EventSubscriber\AccessRevocationSubscriber}'s job.
 */
class UserChecker implements UserCheckerInterface
{
    public function __construct(private readonly AccessGate $gate)
    {
    }

    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        $denial = $this->gate->denialFor($user);
        if (null !== $denial) {
            throw new CustomUserMessageAccountStatusException($denial->message());
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
    }
}
