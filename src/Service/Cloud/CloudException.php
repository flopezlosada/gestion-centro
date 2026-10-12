<?php

declare(strict_types=1);

namespace App\Service\Cloud;

/**
 * The EducaMadrid cloud answered something other than what the call needed. The message is in Spanish
 * and safe to show: it names the step and the status, never the credentials.
 */
class CloudException extends \RuntimeException
{
}
