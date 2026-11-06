<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How many days before a task's deadline its own person wants the first reminder. A closed set, like
 * {@see EventReminderOffset}: the choices fit on one menu and the sweep never meets a nonsensical value.
 * Backed by the number of days.
 */
enum TaskReminderStart: int
{
    case ONE_DAY = 1;
    case TWO_DAYS = 2;
    case THREE_DAYS = 3;
    case FIVE_DAYS = 5;
    case ONE_WEEK = 7;
    case TEN_DAYS = 10;
    case FIFTEEN_DAYS = 15;
    case ONE_MONTH = 30;

    /**
     * Human-facing label (Spanish).
     *
     * @return string the label, e.g. "3 días antes"
     */
    public function label(): string
    {
        return match ($this) {
            self::ONE_DAY => '1 día antes',
            self::ONE_WEEK => '1 semana antes',
            self::ONE_MONTH => '1 mes antes',
            default => \sprintf('%d días antes', $this->value),
        };
    }
}
