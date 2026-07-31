<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How urgent a TIC incident is, in the centre's own three levels. Chosen by whoever reports it, because
 * they are the one who knows whether the class can go on: a projector that flickers and a whole computer
 * room that will not turn on tomorrow morning are not the same thing.
 */
enum IncidentPriority: string
{
    case HIGH = 'high';
    case MEDIUM = 'medium';
    case LOW = 'low';

    /**
     * The label shown when reporting and in the list.
     *
     * @return string the Spanish label
     */
    public function label(): string
    {
        return match ($this) {
            self::HIGH => 'Alta',
            self::MEDIUM => 'Media',
            self::LOW => 'Baja',
        };
    }

    /**
     * One line telling the person which level theirs is, so the choice is not a guess.
     *
     * @return string the Spanish hint
     */
    public function hint(): string
    {
        return match ($this) {
            self::HIGH => 'No se puede dar clase ahí hasta que se arregle.',
            self::MEDIUM => 'Se puede seguir, pero con apaños.',
            self::LOW => 'Molesta, pero puede esperar.',
        };
    }

    /**
     * The tone class the screens paint it with, reusing the palette's semantic tokens.
     *
     * @return string a badge modifier
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::HIGH => 'badge--warning',
            self::MEDIUM => 'badge--amber',
            self::LOW => 'badge--muted',
        };
    }

    /**
     * Sort weight, most urgent first. Materialised as a number because the list is ordered in SQL and
     * "high" sorts after "low" alphabetically, which is exactly backwards.
     *
     * @return int the weight (lower sorts first)
     */
    public function weight(): int
    {
        return match ($this) {
            self::HIGH => 0,
            self::MEDIUM => 1,
            self::LOW => 2,
        };
    }
}
