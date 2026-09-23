<?php

declare(strict_types=1);

namespace App\Util;

use function Symfony\Component\String\u;

/**
 * A name as the database compares it: without case or accents (MariaDB's utf8mb4_unicode_ci), trimmed.
 * What any lookup "is this the same name?" must use before writing, or it misses "Reunion TIC" against
 * "REUNIÓN TIC" and creates a second one next to it.
 */
final class TextKey
{
    /**
     * The comparable key of a name.
     *
     * @param string $name the name
     *
     * @return string the key
     */
    public static function of(string $name): string
    {
        return u($name)->ascii()->lower()->trim()->toString();
    }
}
