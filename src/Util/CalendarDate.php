<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Parsing of the "YYYY-MM-DD" day used across the calendar: the calendar's own anchor and the
 * create forms' date prefill (?fecha=) read the same value, so the (strict, overflow-safe) parsing
 * lives in one place.
 */
final class CalendarDate
{
    /**
     * Parses a "YYYY-MM-DD" day, returning null when the value is missing or not a real calendar
     * date. Validates the actual date with {@see checkdate()} — not just its shape — so overflow days
     * like 2026-02-30 are rejected instead of being silently rolled over into the next month.
     *
     * @param string        $raw      the raw value, expected in "YYYY-MM-DD" form
     * @param \DateTimeZone $timeZone the time zone the resulting midnight is anchored in
     *
     * @return \DateTimeImmutable|null midnight on the day, or null when the value is missing/invalid
     */
    public static function parse(string $raw, \DateTimeZone $timeZone): ?\DateTimeImmutable
    {
        if (1 === preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $parts) && \checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $timeZone);
            if (false !== $parsed) {
                return $parsed;
            }
        }

        return null;
    }
}
