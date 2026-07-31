<?php

declare(strict_types=1);

namespace App\Guardia;

/**
 * One teacher as the break rota engine sees them: a name and how many recreo guardias they take on.
 *
 * The quota is in GUARDIAS, which is what the equipo directivo types, and a guardia is a long recreo
 * plus a short one — so somebody on two owes two places at the long break and two at the short one. The
 * engine works in places and converts here, once, rather than making every caller remember the rule.
 */
final class BreakRotaCandidate
{
    /**
     * @param int    $teacherId the teacher's id
     * @param string $name      full name, used as the deterministic tiebreaker
     * @param int    $quota     recreo guardias they take on over the course
     */
    public function __construct(
        public readonly int $teacherId,
        public readonly string $name,
        public readonly int $quota,
    ) {
    }

    /**
     * How many places at each recreo the quota is worth: one long and one short per guardia.
     *
     * @return int the places owed at each of the two recreos
     */
    public function placesPerBreak(): int
    {
        return max(0, $this->quota);
    }
}
