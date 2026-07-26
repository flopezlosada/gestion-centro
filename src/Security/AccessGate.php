<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Enum\AccessDenial;
use App\Service\AppSettings;

/**
 * The single answer to "may this person be in the application right now?".
 *
 * Two independent switches decide it. The per-user one ({@see User::isActive()}) is the allow-list
 * entry: revoking it takes the person out of the centre altogether. The global one
 * ({@see AppSettings::isLoginOpen()}) puts the whole application in restricted mode, so only the
 * people rolled out so far ({@see User::hasEarlyAccess()}) can get in while it matures.
 *
 * Administrators are exempt from the global switch on purpose: closing sign-in must never be able
 * to lock out the very people who can reopen it. They are NOT exempt from being deactivated, which
 * is a deliberate act on one account and always requires another administrator to undo.
 *
 * One rule, three callers, so none of them can drift from the others: {@see UserChecker} at
 * sign-in, {@see \App\EventSubscriber\AccessRevocationSubscriber} on every later request, and
 * {@see \App\Service\NotificationDispatcher} before mailing anybody (writing to someone who cannot
 * come in only confuses them).
 */
final class AccessGate
{
    public function __construct(private readonly AppSettings $settings)
    {
    }

    /**
     * Why this user may not be in the application, or null when they may.
     *
     * @param User $user the person to check
     *
     * @return AccessDenial|null the reason to turn them away, or null if they are allowed
     */
    public function denialFor(User $user): ?AccessDenial
    {
        if (!$user->isActive()) {
            return AccessDenial::ACCOUNT_DISABLED;
        }

        if ($this->settings->isLoginOpen() || $user->hasEarlyAccess()) {
            return null;
        }

        // Checked last, though it is the widest exemption: reading the roles walks a lazy
        // collection, and this method runs per recipient on the nightly notification batch. With
        // sign-in open the answer is already yes, administrator or not, so the query is wasted.
        //
        // Read from the entity, not from the security token: the gate also runs during
        // authentication, before any token exists, on the User loaded straight from the database.
        if (\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return null;
        }

        return AccessDenial::ACCESS_CLOSED;
    }

    /**
     * Whether this user may be in the application.
     *
     * @param User $user the person to check
     *
     * @return bool true when nothing stands in their way
     */
    public function allows(User $user): bool
    {
        return null === $this->denialFor($user);
    }
}
