<?php

declare(strict_types=1);

namespace App\Service\Cloud;

/**
 * The cloud refused a share because the account already created as many as it allows in a while: Nextcloud
 * 30 lets one account create 20 shares every 10 minutes. Its own type because it is not about the person
 * nor the file — whatever is left waits and is tried again later ({@see CloudShareQueue}).
 */
final class CloudRateLimitedException extends CloudException
{
}
