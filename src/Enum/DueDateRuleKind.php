<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The kind of deadline rule a recurring task template carries, which decides how its due date(s) are
 * computed for a given school year. Each kind maps to one {@see \App\DueDate\DueDateRule}
 * implementation. Kept as a closed set so the editor and the factory stay exhaustive.
 */
enum DueDateRuleKind: string
{
    /** A fixed calendar day, e.g. "30 September". */
    case FIXED = 'fixed';

    /** The Nth weekday of a month, e.g. "first Monday of October". */
    case NTH_WEEKDAY = 'nth_weekday';

    /** A calendar anchor plus an offset, e.g. "5 days before the end of term 1". */
    case RELATIVE_TO_ANCHOR = 'relative_to_anchor';

    /** A given day of every month of the course, e.g. "the 5th of each month". */
    case MONTHLY = 'monthly';

    /** The start or end of every term, e.g. "the end of each term". */
    case PER_TERM = 'per_term';

    /**
     * Human-facing label (Spanish).
     *
     * @return string the kind label
     */
    public function label(): string
    {
        return match ($this) {
            self::FIXED => 'Fecha fija',
            self::NTH_WEEKDAY => 'Día de la semana de un mes',
            self::RELATIVE_TO_ANCHOR => 'Relativa a un hito del curso',
            self::MONTHLY => 'Cada mes',
            self::PER_TERM => 'Cada trimestre',
        };
    }
}
