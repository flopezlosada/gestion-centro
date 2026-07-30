<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Enum\GuardiaDutyBand;

/**
 * The equitable guardia-assignment rule, in one pure function.
 *
 * Given the teachers who can cover a period and how many groups need covering, it returns them in the
 * order they should be picked:
 *   1. band by band ({@see GuardiaDutyBand}: the ordinary rota, then collaborators, then the support
 *      teachers added by hand for the day). A band is only opened while the groups to cover outnumber
 *      the people gathered so far — support duty is a fallback, not part of the ordinary rota;
 *   2. within each band, fewest guardias already done at this period first, then fewest done in total,
 *      then by name — a deterministic tiebreaker so the same person is not always chosen;
 *   3. last of all, and only when every band together still cannot cover every group, the teachers
 *      ALREADY covering a group this period, least burdened first: the centre's "más ausencias que
 *      profes de guardia" case, where somebody has to mind two groups. While the bands suffice, these
 *      are dropped and nobody is ever double-booked.
 *
 * {@see sequence()} turns that ordering into the actual picks, cycling round when the list is shorter
 * than the groups to cover, so a second group only reaches someone once everybody has a first.
 */
final class GuardiaAssigner
{
    /** The bands in the order they are opened. */
    private const array BANDS = [GuardiaDutyBand::GUARDIA, GuardiaDutyBand::COLLABORATOR, GuardiaDutyBand::SUPPORT];

    /**
     * Orders the candidates into the sequence teachers should be assigned in, without repetitions: the
     * available ones band by band, and — only if those cannot cover every group — the ones already
     * covering something this period, least burdened first.
     *
     * @param int                    $coversNeeded how many groups need covering at this period
     * @param list<GuardiaCandidate> $candidates   the teachers who could cover (any band, busy or not)
     *
     * @return list<GuardiaCandidate> the candidates in assignment priority order
     */
    public function prioritise(int $coversNeeded, array $candidates): array
    {
        $available = $this->byBands($coversNeeded, array_values(array_filter(
            $candidates,
            static fn (GuardiaCandidate $c): bool => !$c->isDoublingUp(),
        )));

        // Enough people to go round: doubling up is off the table, so whoever is already covering a
        // group this period is not offered at all (the historical one-teacher-one-group rule).
        if (\count($available) >= $coversNeeded) {
            return $available;
        }

        $busy = array_values(array_filter($candidates, static fn (GuardiaCandidate $c): bool => $c->isDoublingUp()));
        usort($busy, fn (GuardiaCandidate $a, GuardiaCandidate $b): int => $a->hereLoad <=> $b->hereLoad ?: $this->byBalance($a, $b));

        return array_merge($available, $busy);
    }

    /**
     * The actual picks for a period: one candidate per group to cover, in priority order. When the
     * ordered list is shorter than the number of groups — the deficit case — it cycles round from the
     * start, so nobody gets a second group before everybody has a first, and a third before everybody
     * has a second. Returns fewer picks than asked only when there is nobody at all.
     *
     * @param int                    $coversNeeded how many groups need covering at this period
     * @param list<GuardiaCandidate> $candidates   the teachers who could cover (any band, busy or not)
     *
     * @return list<GuardiaCandidate> one pick per group to cover, possibly repeating a teacher
     */
    public function sequence(int $coversNeeded, array $candidates): array
    {
        $ordered = $this->prioritise($coversNeeded, $candidates);
        if ([] === $ordered) {
            return [];
        }

        $picks = [];
        for ($i = 0; $i < $coversNeeded; ++$i) {
            $picks[] = $ordered[$i % \count($ordered)];
        }

        return $picks;
    }

    /**
     * Sorts the available candidates band by band, opening a band only while the groups to cover
     * outnumber the people gathered so far.
     *
     * @param int                    $coversNeeded how many groups need covering
     * @param list<GuardiaCandidate> $available    the candidates not already covering anything
     *
     * @return list<GuardiaCandidate> the candidates of the bands that were needed, in priority order
     */
    private function byBands(int $coversNeeded, array $available): array
    {
        $ordered = [];
        foreach (self::BANDS as $band) {
            if (\count($ordered) >= $coversNeeded) {
                break;
            }
            $inBand = array_values(array_filter($available, static fn (GuardiaCandidate $c): bool => $band === $c->band));
            usort($inBand, $this->byBalance(...));
            $ordered = array_merge($ordered, $inBand);
        }

        return $ordered;
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
