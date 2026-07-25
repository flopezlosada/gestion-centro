<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Single source of truth for the two-letter monogram shown in the avatar circles (topbar, "Más"
 * drawer, task cards and rows, home modules). It used to be a `fullName|split(' ')|map(w => w|first)
 * |join|slice(0, 2)|upper` chain copied into six templates, which invited them to drift apart.
 */
final class Initials
{
    /**
     * The first letter of each word, capped at two and upper-cased.
     *
     * @param string|null $fullName the person's full name; null or blank yields an empty string
     *
     * @return string e.g. "Mercedes Alende" → "MA", "Ana" → "A", "María de la Torre" → "MD"
     */
    public static function of(?string $fullName): string
    {
        if (null === $fullName || '' === trim($fullName)) {
            return '';
        }

        // mb_* throughout so an accented first letter survives ("Ángela Pérez" → "ÁP"), matching what
        // the Twig `first`/`upper` filters did.
        $letters = array_map(
            static fn (string $word): string => mb_substr($word, 0, 1),
            preg_split('/\s+/', trim($fullName)) ?: [],
        );

        return mb_strtoupper(mb_substr(implode('', $letters), 0, 2));
    }
}
