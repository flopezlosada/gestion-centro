<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How long before a personal agenda event its push reminder fires. A closed set (like
 * {@see RecurrenceFrequency}) rather than a free integer, so "avísame en 7 minutos y medio" is
 * unrepresentable and the reminder sweep never has to defend against a nonsensical offset.
 *
 * Backed by the number of minutes, which is also what the column stores: the value IS the offset, so
 * no lookup table is needed to compute {@see \App\Entity\PersonalEvent::getRemindAt()}.
 */
enum EventReminderOffset: int
{
    case FIVE_MINUTES = 5;
    case TEN_MINUTES = 10;
    case FIFTEEN_MINUTES = 15;
    case THIRTY_MINUTES = 30;
    case ONE_HOUR = 60;
    case TWO_HOURS = 120;
    case ONE_DAY = 1440;

    /**
     * Human-facing label (Spanish).
     *
     * @return string the label, e.g. "10 minutos antes"
     */
    public function label(): string
    {
        return match ($this) {
            self::FIVE_MINUTES => '5 minutos antes',
            self::TEN_MINUTES => '10 minutos antes',
            self::FIFTEEN_MINUTES => '15 minutos antes',
            self::THIRTY_MINUTES => '30 minutos antes',
            self::ONE_HOUR => '1 hora antes',
            self::TWO_HOURS => '2 horas antes',
            self::ONE_DAY => '1 día antes',
        };
    }
}
