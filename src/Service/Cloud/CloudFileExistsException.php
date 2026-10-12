<?php

declare(strict_types=1);

namespace App\Service\Cloud;

/**
 * The cloud refused to create a file because one is already at that path ({@see EducamadridCloud::upload()}
 * never overwrites). Its own type so the caller can tell "it is already there" from "it failed".
 */
final class CloudFileExistsException extends CloudException
{
}
