<?php

declare(strict_types=1);

namespace App\Guardia;

/**
 * The equitable guardia-assignment rule, in one pure function.
 *
 * Given the teachers available to cover a period and how many groups need covering, it returns them
 * in the order they should be picked:
 *   1. ordinary guardia teachers first, then collaborators;
 *   2. collaborators are only offered at all when the groups to cover outnumber the guardias
 *      (support duty is a fallback, not part of the ordinary rota);
 *   3. within each band, fewest guardias already done at this period first, then fewest done in
 *      total, then by name — a deterministic tiebreaker so the same person is not always chosen.
 *
 * The caller assigns one teacher per uncovered group by taking the list in order; if it is shorter
 * than the number of groups, the surplus is left unassigned (the parte shows the gap).
 */
final class GuardiaAssigner
{
    /**
     * Orders the available candidates into the sequence teachers should be assigned in.
     *
     * @param int                    $coversNeeded how many groups need covering at this period
     * @param list<GuardiaCandidate> $candidates   the teachers available (guardias and collaborators)
     *
     * @return list<GuardiaCandidate> the candidates in assignment priority order
     */
    public function prioritise(int $coversNeeded, array $candidates): array
    {
        $guardias = array_values(array_filter($candidates, static fn (GuardiaCandidate $c): bool => !$c->collaborator));
        $collaborators = array_values(array_filter($candidates, static fn (GuardiaCandidate $c): bool => $c->collaborator));

        usort($guardias, $this->byBalance(...));
        usort($collaborators, $this->byBalance(...));

        // Collaborators only join when the ordinary guardias cannot cover every group.
        if ($coversNeeded > \count($guardias)) {
            return array_merge($guardias, $collaborators);
        }

        return $guardias;
    }

    /**
     * Comparator implementing the tiebreak chain: per-period load, then total load, then name.
     *
     * @param GuardiaCandidate $a the first candidate
     * @param GuardiaCandidate $b the second candidate
     *
     * @return int negative if $a should come first, positive if $b should, 0 if indistinguishable
     */
    private function byBalance(GuardiaCandidate $a, GuardiaCandidate $b): int
    {
        return $a->slotLoad <=> $b->slotLoad
            ?: $a->totalLoad <=> $b->totalLoad
            ?: strcasecmp($a->teacher->getFullName(), $b->teacher->getFullName());
    }
}
