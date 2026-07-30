<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Enum\ScheduleActivityKind;
use App\Enum\Weekday;

/**
 * Proposes the week's guardia rota from the timetable: who is on duty in each teaching period, within
 * the quotas the equipo directivo set.
 *
 * This is the engine the centre asked for — "que el programa proponga el cuadrante" — and it proposes,
 * nothing more: it returns a draft that a human reads, retouches and approves. That is not a limitation,
 * it is the shape of the requirement, and it drives two decisions.
 *
 * **Why a greedy pass and not a solver.** The problem would fit a min-cost flow, and on 150 places it
 * would solve instantly. It is still the wrong tool. Two of the preferences below are not linear
 * (spreading somebody's guardias across days, keeping people out of periods that would make them come
 * in early), the result would arrive as an optimum nobody can interrogate, and the equipo directivo has
 * to be able to look at a cell and understand why that person is in it before moving them. A greedy
 * pass with an explicit tiebreak chain is defensible line by line and runs in milliseconds.
 *
 * **Hardest period first.** Periods are filled in order of how few people could take them, which is the
 * classic most-constrained-first heuristic. Without it the engine spends its quotas on the easy periods
 * and discovers on Friday first thing — one single candidate on the centre's real export — that there is
 * nobody left, when there was somebody all along.
 *
 * The hard rules: nobody is placed in a period they teach, nobody twice in the same period, nobody over
 * their quota, and a quota of zero never appears. Then, in this order, the preferences:
 *   1. a gap between the teacher's own classes before one at either end of their day — being asked to
 *      come in an hour early is a real cost, and inside a period it is not a fairness question but a
 *      harm to avoid; the quota is what keeps this from loading the rota onto whoever has free periods;
 *   2. whoever has used the least of their own quota, as a fraction — with unequal quotas a raw count
 *      would keep picking the person who was given four;
 *   3. whoever has fewest guardias that day already, so a week does not land on one morning;
 *   4. name, so the same input always produces the same rota.
 *
 * Deliberately pure and free of randomness: same timetable and same quotas, same proposal, which is what
 * makes it testable and what lets somebody re-run it after an edit without the rest of the week moving
 * under their feet.
 */
final class RotaProposer
{
    /** No candidate was free in that period at all — the timetable itself is the constraint. */
    public const GAP_NOBODY_FREE = 'nobody-free';

    /** People were free, but every one of them had already used up their quota. */
    public const GAP_QUOTA_EXHAUSTED = 'quota-exhausted';

    /**
     * Draws up the week.
     *
     * @param list<int>            $slots      the teaching period indexes of the centre's day
     * @param list<RotaCandidate>  $candidates the teachers who may be placed (quota 0 ones are ignored)
     * @param list<array{weekday: int, slot: int, teacherId: int, kind: ScheduleActivityKind}> $fixed
     *                                         places already decided by hand, which are honoured as-is
     *                                         and count against their teacher's quota
     *
     * @return RotaProposal the draft and the report of what it could not fill
     */
    public function propose(array $slots, array $candidates, array $fixed = []): RotaProposal
    {
        // A quota of zero is an exemption: dropping those here means they cannot be reached by any
        // later step, rather than relying on every comparison to remember to skip them.
        $eligible = array_values(array_filter($candidates, static fn (RotaCandidate $c): bool => $c->quota > 0));
        $byId = [];
        foreach ($eligible as $candidate) {
            $byId[$candidate->teacherId] = $candidate;
        }

        $assigned = [];
        $perDay = [];
        $inCell = [];
        $placements = [];

        foreach ($fixed as $place) {
            $key = self::cellKey($place['weekday'], $place['slot']);
            $id = $place['teacherId'];
            $placements[] = [
                'weekday' => $place['weekday'],
                'slot' => $place['slot'],
                'teacherId' => $id,
                'kind' => $place['kind']->value,
                'fixed' => true,
            ];
            $inCell[$key][$id] = true;
            $assigned[$id] = ($assigned[$id] ?? 0) + 1;
            $perDay[$id][$place['weekday']] = ($perDay[$id][$place['weekday']] ?? 0) + 1;
        }

        $cells = $this->cellsHardestFirst($slots, $eligible);
        $needed = $this->stillNeededPerCell($cells, $fixed);

        // Round by round across the WHOLE week, not period by period. Filling each period to five
        // before moving on spends a tight quota on the first periods and leaves the last ones nearly
        // empty — on the centre's real numbers that came out as 19 placements on Monday against 26 on
        // Thursday. Going round by round means every period gets its first guardia before anybody gets
        // a second, so a shortage lands on the standby places, which is what they are for.
        $unfilled = [];
        foreach ($this->rounds() as $round) {
            foreach ($cells as [$weekday, $slot]) {
                $key = self::cellKey($weekday, $slot);
                if (($needed[$key][$round->value] ?? 0) < 1) {
                    continue;
                }
                --$needed[$key][$round->value];

                $pick = $this->best($eligible, $weekday, $slot, $inCell[$key] ?? [], $assigned, $perDay);
                if (null === $pick) {
                    $unfilled[] = [
                        'weekday' => $weekday,
                        'slot' => $slot,
                        'kind' => $round->value,
                        'reason' => $this->whyNobody($eligible, $weekday, $slot, $inCell[$key] ?? [], $assigned),
                    ];
                    continue;
                }

                $placements[] = [
                    'weekday' => $weekday,
                    'slot' => $slot,
                    'teacherId' => $pick->teacherId,
                    'kind' => $round->value,
                    'fixed' => false,
                ];
                $inCell[$key][$pick->teacherId] = true;
                $assigned[$pick->teacherId] = ($assigned[$pick->teacherId] ?? 0) + 1;
                $perDay[$pick->teacherId][$weekday] = ($perDay[$pick->teacherId][$weekday] ?? 0) + 1;
            }
        }

        // Back into reading order for anything that displays the draft: the engine filled the week
        // hardest-period-first, which is an implementation detail nobody should have to see.
        usort($placements, static fn (array $a, array $b): int => [$a['weekday'], $a['slot']] <=> [$b['weekday'], $b['slot']]);
        usort($unfilled, static fn (array $a, array $b): int => [$a['weekday'], $a['slot']] <=> [$b['weekday'], $b['slot']]);

        return new RotaProposal($placements, $unfilled, $this->loadPerTeacher($eligible, $assigned));
    }

    /**
     * Every period of the week, fewest possible candidates first.
     *
     * @param list<int>           $slots      the teaching period indexes
     * @param list<RotaCandidate> $candidates the eligible teachers
     *
     * @return list<array{0: int, 1: int}> the periods as [weekday, slot], hardest first
     */
    private function cellsHardestFirst(array $slots, array $candidates): array
    {
        $cells = [];
        foreach (Weekday::schoolWeek() as $weekday) {
            foreach ($slots as $slot) {
                $free = 0;
                foreach ($candidates as $candidate) {
                    if ($candidate->isFreeAt($weekday->value, $slot)) {
                        ++$free;
                    }
                }
                $cells[] = ['weekday' => $weekday->value, 'slot' => $slot, 'free' => $free];
            }
        }

        // Weekday and slot as tiebreakers, so two equally hard periods always come out in the same
        // order and the whole proposal stays reproducible.
        usort($cells, static fn (array $a, array $b): int => [$a['free'], $a['weekday'], $a['slot']] <=> [$b['free'], $b['weekday'], $b['slot']]);

        return array_map(static fn (array $cell): array => [$cell['weekday'], $cell['slot']], $cells);
    }

    /**
     * The filling rounds, in order: every period gets a first guardia, then a second, and so on, and the
     * standby places only once the guardias are done everywhere.
     *
     * @return list<ScheduleActivityKind> one entry per round
     */
    private function rounds(): array
    {
        return RotaDemand::shifts();
    }

    /**
     * How many places of each kind every period still needs once the ones pinned by hand are taken off.
     *
     * @param list<array{0: int, 1: int}>                                                     $cells the periods
     * @param list<array{weekday: int, slot: int, teacherId: int, kind: ScheduleActivityKind}> $fixed the hand-pinned places
     *
     * @return array<string, array<string, int>> cell key → kind → places still to fill
     */
    private function stillNeededPerCell(array $cells, array $fixed): array
    {
        $needed = [];
        foreach ($cells as [$weekday, $slot]) {
            $key = self::cellKey($weekday, $slot);
            $needed[$key] = [
                ScheduleActivityKind::GUARDIA->value => RotaDemand::GUARDIAS_PER_SLOT,
                ScheduleActivityKind::COLLABORATOR->value => RotaDemand::SUPPORT_PER_SLOT,
            ];
        }

        foreach ($fixed as $place) {
            $key = self::cellKey($place['weekday'], $place['slot']);
            if (isset($needed[$key][$place['kind']->value])) {
                $needed[$key][$place['kind']->value] = max(0, $needed[$key][$place['kind']->value] - 1);
            }
        }

        return $needed;
    }

    /**
     * The best candidate for one place, or null when there is none.
     *
     * A linear scan rather than a sort: only the minimum is wanted, and this runs once per place.
     *
     * @param list<RotaCandidate>     $candidates the eligible teachers
     * @param int                     $weekday    the weekday
     * @param int                     $slot       the period index
     * @param array<int, bool>        $taken      teacher ids already placed in this period
     * @param array<int, int>         $assigned   teacher id → guardias already given
     * @param array<int, array<int, int>> $perDay teacher id → weekday → guardias already given that day
     *
     * @return RotaCandidate|null the pick
     */
    private function best(array $candidates, int $weekday, int $slot, array $taken, array $assigned, array $perDay): ?RotaCandidate
    {
        $best = null;
        $bestScore = null;

        foreach ($candidates as $candidate) {
            $id = $candidate->teacherId;
            if (isset($taken[$id]) || !$candidate->isFreeAt($weekday, $slot)) {
                continue;
            }
            $done = $assigned[$id] ?? 0;
            if ($done >= $candidate->quota) {
                continue;
            }

            $score = [
                $candidate->isMidMorningAt($weekday, $slot) ? 0 : 1,
                $done / $candidate->quota,
                $perDay[$id][$weekday] ?? 0,
                mb_strtolower($candidate->name),
            ];

            if (null === $bestScore || $score < $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * Tells apart the two ways a place can go unfilled, because they call for opposite responses: a
     * period nobody is free in is a timetable problem, and a period where everybody free is already at
     * their quota is fixed by raising a quota.
     *
     * @param list<RotaCandidate> $candidates the eligible teachers
     * @param int                 $weekday    the weekday
     * @param int                 $slot       the period index
     * @param array<int, bool>    $taken      teacher ids already placed in this period
     * @param array<int, int>     $assigned   teacher id → guardias already given
     *
     * @return string one of the GAP_* reasons
     */
    private function whyNobody(array $candidates, int $weekday, int $slot, array $taken, array $assigned): string
    {
        foreach ($candidates as $candidate) {
            if (isset($taken[$candidate->teacherId]) || !$candidate->isFreeAt($weekday, $slot)) {
                continue;
            }

            return self::GAP_QUOTA_EXHAUSTED;
        }

        return self::GAP_NOBODY_FREE;
    }

    /**
     * What each teacher ended up carrying, heaviest shortfall first so the report opens on whoever has
     * quota going spare.
     *
     * @param list<RotaCandidate> $candidates the eligible teachers
     * @param array<int, int>     $assigned   teacher id → guardias given
     *
     * @return list<array{teacherId: int, name: string, quota: int, assigned: int}> the per-teacher load
     */
    private function loadPerTeacher(array $candidates, array $assigned): array
    {
        $rows = array_map(static fn (RotaCandidate $c): array => [
            'teacherId' => $c->teacherId,
            'name' => $c->name,
            'quota' => $c->quota,
            'assigned' => $assigned[$c->teacherId] ?? 0,
        ], $candidates);

        usort($rows, static fn (array $a, array $b): int => [$b['quota'] - $b['assigned'], mb_strtolower($a['name'])] <=> [$a['quota'] - $a['assigned'], mb_strtolower($b['name'])]);

        return $rows;
    }

    /**
     * @param int $weekday the weekday
     * @param int $slot    the period index
     *
     * @return string the key identifying one period of the week
     */
    private static function cellKey(int $weekday, int $slot): string
    {
        return $weekday.':'.$slot;
    }
}
