<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Which of the day's two recreos a duty is for: the long morning one or the short one before the last
 * period.
 *
 * It replaces the old {@code BreakPeriodCoverage}, and the difference is the centre's counting rule, not
 * a rename. That enum had a {@code BOTH} case because one row WAS one guardia however many recreos it
 * spanned. The centre then settled the rule differently: **one guardia is one long recreo plus one short
 * one, and they need not be on the same day**. Spanning both stops being a property of a single row, so
 * a row becomes one PLACE — this period, this day, this zone — and the guardia becomes a count over
 * them. Keeping {@code BOTH} would have made the two rules coexist in the same column.
 *
 * Positional, not clock times: {@see FIRST} is the first break of the day and {@see SECOND} the next,
 * whatever hours the centre's marco horario gives them ({@see \App\Entity\TimeSlot}). The centre has
 * exactly two.
 */
enum BreakPeriod: string
{
    case FIRST = 'first';
    case SECOND = 'second';

    /**
     * Where the break sits in the day, 0-based — the index used against the course's break time slots.
     *
     * @return int 0 for the first break, 1 for the second
     */
    public function position(): int
    {
        return match ($this) {
            self::FIRST => 0,
            self::SECOND => 1,
        };
    }

    /**
     * The case for a 0-based position in the day.
     *
     * @param int $position 0 for the first break, 1 for the second
     *
     * @return self the matching period
     */
    public static function fromPosition(int $position): self
    {
        return match ($position) {
            0 => self::FIRST,
            1 => self::SECOND,
            default => throw new \InvalidArgumentException(sprintf('El centro solo tiene dos recreos; no existe el de posición %d.', $position)),
        };
    }

    /**
     * Human-facing label (Spanish), naming the recreo by its place in the day and its length — which is
     * what the centre actually calls them ("el grande" y "el corto").
     *
     * @return string the label
     */
    public function label(): string
    {
        return match ($this) {
            self::FIRST => 'Recreo grande',
            self::SECOND => 'Recreo corto',
        };
    }

    /**
     * Compact label for a grid cell, where the zone and the teacher already take the room.
     *
     * @return string the short label
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::FIRST => '1.º',
            self::SECOND => '2.º',
        };
    }

    /**
     * Both recreos, in the order they happen.
     *
     * @return list<self> the day's breaks
     */
    public static function inDayOrder(): array
    {
        return [self::FIRST, self::SECOND];
    }
}
