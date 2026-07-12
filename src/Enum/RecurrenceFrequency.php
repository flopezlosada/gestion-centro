<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How often a personal agenda event repeats. Kept as a closed set (like {@see DueDateRuleKind}) so the
 * form and the {@see \App\Agenda\RecurrenceExpander} stay exhaustive — an unknown value is impossible
 * rather than silently treated as "no repeat".
 */
enum RecurrenceFrequency: string
{
    /** A one-off event: no repetition. */
    case NONE = 'none';

    /** Repeats every seven days. */
    case WEEKLY = 'weekly';

    /** Repeats on the same day of every month (clamped to each month's length). */
    case MONTHLY = 'monthly';

    /**
     * Human-facing label (Spanish).
     *
     * @return string the frequency label
     */
    public function label(): string
    {
        return match ($this) {
            self::NONE => 'No se repite',
            self::WEEKLY => 'Cada semana',
            self::MONTHLY => 'Cada mes',
        };
    }
}
