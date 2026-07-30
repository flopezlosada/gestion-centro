<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Which of the day's recreos a break duty covers: only the long morning one, only the short one, or
 * both.
 *
 * This enum is how the centre's counting rule — "cubrir los dos tramos cuenta como UNA sola guardia" —
 * becomes a fact of the model instead of a rule somebody has to remember in every query: a duty is one
 * row of {@see \App\Entity\BreakDutyAssignment} whatever it spans, so counting rows already counts
 * guardias. It also rules out the state two booleans would allow, a duty covering no recreo at all.
 *
 * The two cases are positional, not clock times: {@see FIRST} is the first break of the day and
 * {@see SECOND} the next one, whatever hours the centre's marco horario gives them
 * ({@see \App\Entity\TimeSlot}). The centre has exactly two; a third would need a migration, which is
 * the honest trade for making the counting rule structural.
 */
enum BreakPeriodCoverage: string
{
    case FIRST = 'first';
    case SECOND = 'second';
    case BOTH = 'both';

    /**
     * Human-facing label (Spanish), naming the recreo by its place in the day.
     *
     * @return string the coverage label
     */
    public function label(): string
    {
        return match ($this) {
            self::FIRST => 'Primer recreo',
            self::SECOND => 'Segundo recreo',
            self::BOTH => 'Los dos recreos',
        };
    }

    /**
     * Compact label for a rota cell, where the zone and the teacher already take the room.
     *
     * @return string the short coverage label
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::FIRST => '1.º',
            self::SECOND => '2.º',
            self::BOTH => '1.º+2.º',
        };
    }

    /**
     * Whether this coverage includes the break at the given position in the day.
     *
     * @param int $position 0 for the first break of the day, 1 for the second
     *
     * @return bool true when the duty covers that break
     */
    public function includes(int $position): bool
    {
        return match ($this) {
            self::FIRST => 0 === $position,
            self::SECOND => 1 === $position,
            self::BOTH => $position <= 1,
        };
    }

    /**
     * The break positions this coverage spans, in order.
     *
     * @return list<int> the positions (0 = first break, 1 = second)
     */
    public function positions(): array
    {
        return match ($this) {
            self::FIRST => [0],
            self::SECOND => [1],
            self::BOTH => [0, 1],
        };
    }
}
