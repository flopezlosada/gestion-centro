<?php

declare(strict_types=1);

namespace App\Service\Cloud;

/**
 * What the centre's EducaMadrid cloud account hands back after the Login Flow: the account's login and
 * an app password — a token the account owner can revoke from their own cloud settings without ever
 * telling us their real password.
 *
 * Note what an app password is in Nextcloud: a key to the WHOLE account, not to one folder. That is why
 * the account connected here is the centre's institutional one and never a person's.
 */
final readonly class CloudCredentials
{
    public function __construct(
        public string $login,
        public string $appPassword,
    ) {
    }
}
