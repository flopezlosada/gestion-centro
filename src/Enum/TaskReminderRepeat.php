<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How often a task reminder repeats after the first one, until the day before the deadline. Backed by
 * the gap in days; {@see ONCE} (0) means no repetition.
 */
enum TaskReminderRepeat: int
{
    case ONCE = 0;
    case DAILY = 1;
    case EVERY_TWO_DAYS = 2;
    case EVERY_THREE_DAYS = 3;
    case WEEKLY = 7;

    /**
     * Human-facing label (Spanish).
     *
     * @return string the label, e.g. "Cada 2 días"
     */
    public function label(): string
    {
        return match ($this) {
            self::ONCE => 'Solo una vez',
            self::DAILY => 'Cada día',
            self::WEEKLY => 'Cada semana',
            default => \sprintf('Cada %d días', $this->value),
        };
    }
}
