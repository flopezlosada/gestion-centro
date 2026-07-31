<?php

declare(strict_types=1);

namespace App\Guardia;

/**
 * One teacher as the rota engine sees them: a quota, and the periods they teach.
 *
 * Everything else the engine needs is derived from those teaching periods rather than passed in, so
 * there is one source of truth about a teacher's week and no way for a caller to hand over a free-slot
 * list that disagrees with the timetable it came from.
 *
 * The distinction that matters most here is {@see isMidMorningAt()}. A gap between two of somebody's
 * own classes costs them nothing — they are in the building anyway — while a period before their first
 * class or after their last means coming in early or staying late for it. The timetable cannot tell you
 * which is which on its own: on the centre's real export, "free and present" reads as zero candidates
 * in the last period from Tuesday to Friday, because whoever is free then is free precisely by having
 * gone home. So both are allowed and the engine prefers the harmless one.
 */
final class RotaCandidate
{
    /**
     * @param int                   $teacherId  the teacher's id
     * @param string                $name       full name, used as the deterministic tiebreaker
     * @param int                   $quota      how many guardias they take on over the course
     * @param array<int, list<int>> $busySlots  weekday (ISO, Monday = 1) → the period indexes they teach
     */
    public function __construct(
        public readonly int $teacherId,
        public readonly string $name,
        public readonly int $quota,
        private readonly array $busySlots = [],
    ) {
    }

    /**
     * Whether the teacher has no class in this period, and so could take a guardia in it.
     *
     * @param int $weekday the weekday, ISO (Monday = 1)
     * @param int $slot    the period index
     *
     * @return bool true when the period is free
     */
    public function isFreeAt(int $weekday, int $slot): bool
    {
        return !\in_array($slot, $this->busySlots[$weekday] ?? [], true);
    }

    /**
     * Whether this free period falls between two of the teacher's own classes — the gap that costs them
     * nothing because they are already in the building.
     *
     * A day with no classes at all has no span, so nothing on it counts as mid-morning: the teacher
     * would be coming in for the guardia alone.
     *
     * @param int $weekday the weekday, ISO (Monday = 1)
     * @param int $slot    the period index
     *
     * @return bool true when the period sits inside the teacher's teaching day
     */
    public function isMidMorningAt(int $weekday, int $slot): bool
    {
        $teaching = $this->busySlots[$weekday] ?? [];
        if ([] === $teaching) {
            return false;
        }

        return $slot > min($teaching) && $slot < max($teaching);
    }

    /**
     * How many periods a week the teacher has class — shown beside a proposal so a human retouching it
     * can see who is already carrying a heavy timetable.
     *
     * @return int the teaching periods a week
     */
    public function teachingLoad(): int
    {
        return array_sum(array_map('count', $this->busySlots));
    }
}
