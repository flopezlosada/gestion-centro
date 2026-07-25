<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Shortens free text for a list cell without cutting a word in half. Slicing at a fixed character
 * count leaves stumps like "se recogen al final de la hor…", which reads as a rendering bug; this
 * backs up to the last word boundary instead.
 */
final class Excerpt
{
    /**
     * @param string|null $text  the full text; null or blank yields an empty string
     * @param int         $limit maximum length of the result, ellipsis included
     *
     * @return string the text as is when it fits, otherwise cut at a word boundary plus "…"
     */
    public static function of(?string $text, int $limit = 60): string
    {
        $text = trim((string) $text);
        if ('' === $text || mb_strlen($text) <= $limit) {
            return $text;
        }

        // One char is reserved for the ellipsis. Cutting at the last space keeps whole words; with no
        // space in range (a single long token) the hard cut is the only option left.
        $cut = mb_substr($text, 0, $limit - 1);
        $lastSpace = mb_strrpos($cut, ' ');

        return rtrim(false !== $lastSpace && $lastSpace > 0 ? mb_substr($cut, 0, $lastSpace) : $cut, " ,.;:").'…';
    }
}
