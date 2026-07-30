<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How much fits in a space, measured the way the centre actually measures it: in GROUPS, not in seats.
 *
 * The centre's own words (2026-07-30): "aulas normales, aulas específicas de pequeño tamaño (para 15
 * alumnos), aulas grandes de gran tamaño (para dos grupos, para más de tres grupos)". Nobody at the
 * centre knows how many students are in each group — Peñalara exports no enrolment — but everybody knows
 * whether two groups fit in a room. Asking for a seat count would mean asking people to invent numbers;
 * asking "how many groups" is a question they can answer without looking anything up.
 *
 * This is the primary size criterion. {@see \App\Entity\Room::$capacity} stays as an optional seat
 * count, useful where the limit really is a headcount (a 15-seat specific room, ordering photocopies),
 * but a room can be relocated by size alone.
 */
enum RoomSize: string
{
    /** Half a group: a desdoble room, roughly 15 students. A whole group does NOT fit. */
    case SMALL = 'small';

    /** An ordinary classroom: one group. */
    case ONE_GROUP = 'one_group';

    /** A big room: two groups together. */
    case TWO_GROUPS = 'two_groups';

    /** The assembly hall and the like: three groups or more. */
    case MANY_GROUPS = 'many_groups';

    /**
     * Human-facing name (Spanish), in the centre's own terms.
     *
     * @return string the size label
     */
    public function label(): string
    {
        return match ($this) {
            self::SMALL => 'Pequeña (medio grupo, ~15)',
            self::ONE_GROUP => 'Normal (un grupo)',
            self::TWO_GROUPS => 'Grande (dos grupos)',
            self::MANY_GROUPS => 'Muy grande (tres grupos o más)',
        };
    }

    /**
     * How many whole groups fit, as a number that can be compared. Zero for a room that cannot hold a
     * whole group at all.
     *
     * @return int the number of groups
     */
    public function groups(): int
    {
        return match ($this) {
            self::SMALL => 0,
            self::ONE_GROUP => 1,
            self::TWO_GROUPS => 2,
            self::MANY_GROUPS => 3,
        };
    }

    /**
     * Whether a room of this size can take what fitted in a room of the given size.
     *
     * @param self $needed the size of the room being left
     *
     * @return bool true when it fits
     */
    public function fits(self $needed): bool
    {
        return $this->groups() >= $needed->groups();
    }

    /**
     * The curated order in which sizes are offered, smallest first.
     *
     * @return list<RoomSize> every size, in display order
     */
    public static function inDisplayOrder(): array
    {
        return [self::SMALL, self::ONE_GROUP, self::TWO_GROUPS, self::MANY_GROUPS];
    }
}
