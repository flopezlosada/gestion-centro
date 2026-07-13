<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The category of a personal agenda event, used to colour-code it in the agenda and calendar. A
 * closed set (like {@see DueDateRuleKind} / {@see RecurrenceFrequency}) so the form and the views
 * stay exhaustive. Each category maps to a colour via the CSS class {@see self::cssClass()} — the
 * actual colour token lives in the stylesheet, not here.
 */
enum EventCategory: string
{
    case GENERAL = 'general';
    case TEACHING = 'teaching';
    case MEETING = 'meeting';
    case TUTORING = 'tutoring';
    case PERSONAL = 'personal';

    /**
     * Human-facing label (Spanish).
     *
     * @return string the category label
     */
    public function label(): string
    {
        return match ($this) {
            self::GENERAL => 'General',
            self::TEACHING => 'Docencia',
            self::MEETING => 'Reunión',
            self::TUTORING => 'Tutoría',
            self::PERSONAL => 'Personal',
        };
    }

    /**
     * The CSS class that carries this category's colour (defined in the stylesheet as a custom
     * property, so a single class colours both the calendar dot and the agenda badge).
     *
     * @return string the CSS class
     */
    public function cssClass(): string
    {
        return 'event-cat--'.$this->value;
    }
}
