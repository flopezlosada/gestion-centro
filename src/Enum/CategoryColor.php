<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The fixed palette a {@see \App\Entity\EventCategory} may pick its colour from. A closed set (like
 * {@see RecurrenceFrequency}) so the picker stays exhaustive and every colour maps to an existing
 * theme token — light/dark support for free, no loose hex. The actual token lives in the stylesheet,
 * keyed by {@see self::cssClass()}.
 */
enum CategoryColor: string
{
    case SLATE = 'slate';
    case TEAL = 'teal';
    case BLUE = 'blue';
    case GREEN = 'green';
    case AMBER = 'amber';
    case RED = 'red';

    /**
     * Human-facing colour name (Spanish).
     *
     * @return string the colour label
     */
    public function label(): string
    {
        return match ($this) {
            self::SLATE => 'Gris',
            self::TEAL => 'Verde azulado',
            self::BLUE => 'Azul',
            self::GREEN => 'Verde',
            self::AMBER => 'Ámbar',
            self::RED => 'Rojo',
        };
    }

    /**
     * The CSS class that carries this colour (defined in the stylesheet as a custom property, shared
     * by the calendar dot and the agenda badge).
     *
     * @return string the CSS class
     */
    public function cssClass(): string
    {
        return 'cat-color--'.$this->value;
    }
}
