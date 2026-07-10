<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Single source of truth for turning a fixed-scale numeric value (a Doctrine DECIMAL, which arrives
 * as a string padded to its scale such as "150.000") into the way it is shown to people: no trailing
 * zeros, decimal point, no thousands separator. Shared by the Twig filter and the measurement form
 * so the table and the edit input agree.
 */
final class DecimalFormatter
{
    /**
     * @param string|int|float|null $value the raw value; null/'' yield an empty string
     *
     * @return string e.g. "150.000" → "150", "1.500" → "1.5", "100.000" → "100"
     */
    public static function display(string|int|float|null $value): string
    {
        if (null === $value || '' === $value) {
            return '';
        }

        $string = (string) $value;

        // Only a value with a decimal point can carry trailing zeros to drop, and rtrim stops at the
        // point, so the integer part is never touched ("100.000" → "100", not "1"). Working on the
        // string (not a float) keeps the full DECIMAL precision.
        return str_contains($string, '.') ? rtrim(rtrim($string, '0'), '.') : $string;
    }
}
