<?php

declare(strict_types=1);

namespace App\Space;

use App\Entity\SpacePlan;

/**
 * The approved space plans covering one day, asked the one question both readers of the effective
 * timetable need: does any of them take this group out of its ordinary timetable at this period?
 *
 * A class rather than the same loop written twice ({@see RoomOccupancy}, {@see EffectiveTimetable}),
 * because the two must never disagree: if one of them thought a group still had its ordinary lesson and
 * the other did not, the same period would be free on one screen and taken on the next.
 */
final readonly class ApprovedPlans
{
    /**
     * @param list<SpacePlan> $plans the approved plans covering the day
     */
    public function __construct(
        public array $plans,
    ) {
    }

    /**
     * Whether nothing is approved for the day — the common case, and worth checking before making further
     * queries that can only come back empty.
     *
     * @return bool true when no plan is in force
     */
    public function isEmpty(): bool
    {
        return [] === $this->plans;
    }

    /**
     * Whether some plan replaces the given group's ordinary timetable at that period.
     *
     * @param \DateTimeImmutable $date      the day
     * @param int                $slotIndex the period index
     * @param string|null        $groupName the group, or null for a cell with no group
     *
     * @return bool true when that group has no ordinary lesson then
     */
    public function replaceTimetableFor(\DateTimeImmutable $date, int $slotIndex, ?string $groupName): bool
    {
        foreach ($this->plans as $plan) {
            if ($plan->covers($date, $slotIndex) && $plan->replacesTimetableFor($groupName)) {
                return true;
            }
        }

        return false;
    }
}
