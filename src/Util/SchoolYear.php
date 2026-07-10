<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Helpers around the academic school year used as the period key for tasks and their recurring
 * templates, always in the canonical "YYYY-YYYY" format.
 *
 * The school year runs from September to August: a date in September or later belongs to the
 * "current year → next year" course; before September it belongs to the previous course.
 */
final class SchoolYear
{
    /** The course starts in September (month 9). */
    private const int FIRST_MONTH = 9;

    /**
     * The school year a date falls into, in "YYYY-YYYY" format (e.g. 2026-02-01 → "2025-2026",
     * 2025-10-01 → "2025-2026").
     *
     * @param \DateTimeImmutable $on the reference date
     *
     * @return string the school year in "YYYY-YYYY" format
     */
    public static function current(\DateTimeImmutable $on): string
    {
        $year = (int) $on->format('Y');
        $start = (int) $on->format('n') >= self::FIRST_MONTH ? $year : $year - 1;

        return sprintf('%d-%d', $start, $start + 1);
    }

    /**
     * The school year that follows the given one, shifting the window by one year
     * (e.g. "2025-2026" → "2026-2027"). Parsing is tolerant of the start year only, so it also
     * normalises slash-separated values ("2025/2026" → "2026-2027").
     *
     * @param string $schoolYear the school year to advance from; its first four digits are its start
     *
     * @return string the next school year in canonical "YYYY-YYYY" format
     */
    public static function next(string $schoolYear): string
    {
        $start = (int) substr($schoolYear, 0, 4);

        return sprintf('%d-%d', $start + 1, $start + 2);
    }

    /**
     * The school year that precedes the given one (e.g. "2025-2026" → "2024-2025"). Tolerant of the
     * start year only, so it also normalises slash-separated values.
     *
     * @param string $schoolYear the school year to step back from; its first four digits are its start
     *
     * @return string the previous school year in canonical "YYYY-YYYY" format
     */
    public static function previous(string $schoolYear): string
    {
        $start = (int) substr($schoolYear, 0, 4);

        return sprintf('%d-%d', $start - 1, $start);
    }
}
