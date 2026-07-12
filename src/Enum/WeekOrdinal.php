<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Which occurrence of a weekday within a month a deadline falls on: the first through fourth, or the
 * last. (A fifth occurrence is intentionally not offered — it does not exist every month; "last"
 * covers that intent unambiguously.)
 */
enum WeekOrdinal: string
{
    case FIRST = 'first';
    case SECOND = 'second';
    case THIRD = 'third';
    case FOURTH = 'fourth';
    case LAST = 'last';

    /**
     * The zero-based week offset for the first four ordinals; null for {@see self::LAST}, which is
     * computed from the end of the month instead.
     *
     * @return int|null the number of whole weeks to add to the first occurrence, or null for "last"
     */
    public function weekOffset(): ?int
    {
        return match ($this) {
            self::FIRST => 0,
            self::SECOND => 1,
            self::THIRD => 2,
            self::FOURTH => 3,
            self::LAST => null,
        };
    }

    /**
     * Human-facing label (Spanish).
     *
     * @return string the ordinal label
     */
    public function label(): string
    {
        return match ($this) {
            self::FIRST => 'Primer',
            self::SECOND => 'Segundo',
            self::THIRD => 'Tercer',
            self::FOURTH => 'Cuarto',
            self::LAST => 'Último',
        };
    }
}
