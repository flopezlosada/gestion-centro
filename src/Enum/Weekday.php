<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * A day of the week, numbered as ISO-8601 (Monday = 1 … Sunday = 7), matching PHP's date('N'). Used
 * by the "Nth weekday of a month" deadline rule.
 */
enum Weekday: int
{
    case MONDAY = 1;
    case TUESDAY = 2;
    case WEDNESDAY = 3;
    case THURSDAY = 4;
    case FRIDAY = 5;
    case SATURDAY = 6;
    case SUNDAY = 7;

    /**
     * Human-facing label (Spanish).
     *
     * @return string the weekday label
     */
    public function label(): string
    {
        return match ($this) {
            self::MONDAY => 'Lunes',
            self::TUESDAY => 'Martes',
            self::WEDNESDAY => 'Miércoles',
            self::THURSDAY => 'Jueves',
            self::FRIDAY => 'Viernes',
            self::SATURDAY => 'Sábado',
            self::SUNDAY => 'Domingo',
        };
    }
}
