<?php

declare(strict_types=1);

namespace App\Space;

use App\Entity\SpacePlanAssignment;
use App\Entity\User;

/**
 * Shares out who runs each session of a special day. Pure: no database, no clock — the same input always
 * produces the same rota, which is what makes it reviewable and testable.
 *
 * Three rules, all of them the centre's:
 *  - Nobody is given a session outside their own teaching day. Their words: "si su docencia empieza a
 *    las 9:20, su participación empieza en torno a esa hora". Whoever is not in the building cannot
 *    run a workshop, however free the alternative timetable leaves them.
 *  - The load is shared: the person with least so far goes first. Same principle as
 *    {@see \App\Guardia\GuardiaAssigner} for guardias, and for the same reason — a rota that lands on
 *    the same willing people every year stops being volunteering and becomes a grievance.
 *  - Nobody exceeds the plan's cap, when there is one.
 *
 * WHY THE TIE IS NOT BROKEN ALPHABETICALLY: on a one-off day everybody starts at zero, so sorting by
 * name hands every session to the top of the register — the first run of this over the real roster
 * picked Alberto, Alfredo, Almudena, Ana, Agustín and nine more A's out of seventy-eight people. Ties
 * are broken by a hash of the seed and the person instead: still deterministic (the same plan always
 * produces the same rota, so it can be reviewed and re-run), but a different plan falls on different
 * people. The seed is the caller's business; the plan's id does the job.
 *
 * A session with nobody available is left EMPTY on purpose. That is a real answer — at that hour there
 * genuinely is nobody in the building who is free — and it is the line the equipo directivo has to
 * resolve, by moving the session or by asking somebody to come in.
 */
final class StaffAssigner
{
    /**
     * Assigns a teacher to each session.
     *
     * @param list<SpacePlanAssignment>  $sessions          the sessions to cover, in any order
     * @param array<string, list<User>>  $availableByMoment who is in the centre, keyed by "Y-m-d|slot"
     * @param array<int, int>            $loadSoFar         teacher id → sessions already assigned elsewhere
     * @param int|null                   $quota             most sessions one person may take, or null for no cap
     * @param string                     $seed              what makes one plan's rota differ from another's
     *
     * @return array<int, User|null> the chosen teacher per session, keyed by the session's position in the input
     */
    public function assign(array $sessions, array $availableByMoment, array $loadSoFar = [], ?int $quota = null, string $seed = ''): array
    {
        $load = $loadSoFar;
        // Who is already busy at a moment, so nobody runs two workshops at once.
        $busy = [];

        $chosen = [];
        foreach ($this->hardestFirst($sessions, $availableByMoment) as $index => $session) {
            $moment = self::moment($session);
            $candidates = array_values(array_filter(
                $availableByMoment[$moment] ?? [],
                static fn (User $u): bool => !isset($busy[$moment.'|'.$u->getId()])
                    && (null === $quota || ($load[(int) $u->getId()] ?? 0) < $quota),
            ));

            if ([] === $candidates) {
                $chosen[$index] = null;
                continue;
            }

            usort($candidates, static fn (User $a, User $b): int => [$load[(int) $a->getId()] ?? 0, self::rank($seed, $a)]
                <=> [$load[(int) $b->getId()] ?? 0, self::rank($seed, $b)]);

            $pick = $candidates[0];
            $chosen[$index] = $pick;
            $busy[$moment.'|'.$pick->getId()] = true;
            $load[(int) $pick->getId()] = ($load[(int) $pick->getId()] ?? 0) + 1;
        }

        ksort($chosen);

        return $chosen;
    }

    /**
     * The sessions ordered by how few people can cover them, hardest first, keeping the original index.
     *
     * The order matters more than the choice: filling an easy session first can leave the hard one with
     * nobody, when the other way round both would have been covered.
     *
     * @param list<SpacePlanAssignment> $sessions          the sessions
     * @param array<string, list<User>> $availableByMoment who is in the centre, by moment
     *
     * @return array<int, SpacePlanAssignment> the sessions, hardest first, keyed by original index
     */
    private function hardestFirst(array $sessions, array $availableByMoment): array
    {
        $ordered = $sessions;
        uasort($ordered, static function (SpacePlanAssignment $a, SpacePlanAssignment $b) use ($availableByMoment): int {
            return [\count($availableByMoment[self::moment($a)] ?? []), self::moment($a)]
                <=> [\count($availableByMoment[self::moment($b)] ?? []), self::moment($b)];
        });

        return $ordered;
    }

    /**
     * A person's place in the queue for one plan: stable for that plan, different across plans. What
     * keeps the rota off the top of the alphabet without making it unpredictable.
     *
     * @param string $seed what identifies this rota
     * @param User   $user the person
     *
     * @return string the ranking key
     */
    private static function rank(string $seed, User $user): string
    {
        return md5($seed.':'.$user->getId());
    }

    /**
     * The moment a session happens, as the key availability is indexed by.
     *
     * @param SpacePlanAssignment $session the session
     *
     * @return string "Y-m-d|slot"
     */
    public static function moment(SpacePlanAssignment $session): string
    {
        return $session->getDate()->format('Y-m-d').'|'.$session->getSlotIndex();
    }
}
