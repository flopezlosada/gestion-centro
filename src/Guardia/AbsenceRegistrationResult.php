<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Entity\BreakDutyGap;

/**
 * Outcome of registering a teacher's absence across one or more periods: which periods became a
 * cover, how many were skipped because the teacher was free then or already had a cover, and whether
 * the absence also left a recreo unwatched. Lets the caller give a precise summary ("3 guardias
 * generadas, 2 horas libres omitidas").
 */
final class AbsenceRegistrationResult
{
    /**
     * @param list<int>          $createdSlots    period indices for which a cover was created
     * @param int                $skippedFree     periods skipped because the teacher had no class then
     * @param int                $skippedExisting periods skipped because a cover already existed
     * @param list<BreakDutyGap> $breakGaps       the recreos left unwatched by this absence — recorded,
     *                                            never re-covered, and alerted to the equipo directivo.
     *                                            A list because a teacher can hold a place at each of the
     *                                            day's two recreos, and missing both is two searches for
     *                                            a volunteer, not one
     * @param list<int>          $relievedSlots   periods where this teacher was covering somebody else's
     *                                            class and has now been taken off it — the other half of
     *                                            an absence, and the half that used to be missed
     */
    public function __construct(
        public readonly array $createdSlots,
        public readonly int $skippedFree,
        public readonly int $skippedExisting,
        public readonly array $breakGaps = [],
        public readonly array $relievedSlots = [],
    ) {
    }

    /**
     * The names of the zones this absence leaves unwatched, in order, for the message that says so.
     *
     * @return list<string> the zone names
     */
    public function uncoveredZones(): array
    {
        return array_map(static fn (BreakDutyGap $gap): string => $gap->getAssignment()->getZone()->getName(), $this->breakGaps);
    }

    /**
     * How many guardias this teacher was relieved of.
     *
     * @return int the number of periods they no longer have to cover
     */
    public function relievedCount(): int
    {
        return \count($this->relievedSlots);
    }

    /**
     * How many covers were created.
     *
     * @return int the number of periods turned into a cover
     */
    public function createdCount(): int
    {
        return \count($this->createdSlots);
    }
}
