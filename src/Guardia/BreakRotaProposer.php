<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Enum\BreakPeriod;

/**
 * Proposes the weekly break duty rota: who watches which zone at each recreo, within the quotas the
 * equipo directivo set.
 *
 * A different problem from the teaching rota ({@see RotaProposer}) despite the family resemblance, and
 * worth not forcing into the same shape. There no timetable rules anybody out — every teacher is free at
 * the recreo — so the scarce thing is not availability but FAIRNESS: the centre said the zones do not
 * cost the same, so the job is to spread weight, not to fill slots.
 *
 * **Heaviest place first.** Places are handed out from the most demanding zone down, each to whoever is
 * carrying the least weight so far. That is the classic longest-processing-time rule, and it matters
 * here: give out the biblioteca first and the patios all land on whoever is left, which is exactly the
 * complaint a weighted rota exists to prevent.
 *
 * **Quotas are in guardias, places come in pairs.** Somebody on two guardias owes two long places and two
 * short ones ({@see BreakRotaCandidate::placesPerBreak()}), and the two pools are filled against separate
 * counters. That is what keeps the pairing honest without pairing anything explicitly: if the week holds
 * more long places than short ones — it does, because the patio dirigido is not watched at the short
 * recreo — the surplus simply cannot find a partner, and comes out of the report as halves.
 *
 * The hard rules: nobody twice at the same recreo of the same day, nobody past their quota, and a quota
 * of zero never appears. Then, in order: least weight carried, fewest places at that recreo, a zone they
 * do not already have (the centre asked that people move around), fewest that weekday, and name — so the
 * same input always yields the same rota.
 */
final class BreakRotaProposer
{
    /** Nobody was left: everybody free at that recreo had already used up their quota. */
    public const GAP_QUOTA_EXHAUSTED = 'quota-exhausted';

    /** Everybody available already holds a place at that very recreo, so none of them can take another. */
    public const GAP_NOBODY_LEFT = 'nobody-left';

    /**
     * There was nobody to place at all: no teacher in the course has both a timetable and a recreo quota
     * above zero.
     *
     * Kept apart from the other two because it is not a fact about the place — it is a fact about the
     * whole course, and the only true thing to say about every single gap. Without it these came out as
     * {@see GAP_NOBODY_LEFT}, which reads "everybody is already standing at another zone" when there is
     * nobody standing anywhere: the same default-reason bug the teaching rota had.
     */
    public const GAP_NOBODY_ELIGIBLE = 'nobody-eligible';

    /**
     * Draws up the week.
     *
     * @param list<array{weekday: int, period: BreakPeriod, zoneId: int, weight: int}> $places     every place the week needs, one entry per person needed
     * @param list<BreakRotaCandidate>                                                 $candidates the teachers who may be placed (quota 0 ones are ignored)
     * @param list<array{weekday: int, period: BreakPeriod, zoneId: int, teacherId: int}> $fixed    places already decided by hand, honoured as-is and counted against their quota
     *
     * @return BreakRotaProposal the draft and its report
     */
    public function propose(array $places, array $candidates, array $fixed = []): BreakRotaProposal
    {
        // A quota of zero is an exemption: dropped here so no later step can reach them.
        $eligible = array_values(array_filter($candidates, static fn (BreakRotaCandidate $c): bool => $c->quota > 0));

        $load = [];
        $perBreak = [];
        $perDay = [];
        $zonesHeld = [];
        $inCell = [];
        $placed = [];

        foreach ($fixed as $place) {
            $id = $place['teacherId'];
            $placed[] = [
                'weekday' => $place['weekday'],
                'period' => $place['period']->value,
                'zoneId' => $place['zoneId'],
                'teacherId' => $id,
                'fixed' => true,
            ];
            $this->take($id, $place['weekday'], $place['period'], $place['zoneId'], $this->weightOf($places, $place), $load, $perBreak, $perDay, $zonesHeld, $inCell);
        }

        $unfilled = [];
        foreach ($this->heaviestFirst($places, $fixed) as $place) {
            $pick = $this->best($eligible, $place, $load, $perBreak, $perDay, $zonesHeld, $inCell);

            if (null === $pick) {
                $unfilled[] = [
                    'weekday' => $place['weekday'],
                    'period' => $place['period']->value,
                    'zoneId' => $place['zoneId'],
                    'reason' => $this->whyNobody($eligible, $place, $perBreak, $inCell),
                ];
                continue;
            }

            $placed[] = [
                'weekday' => $place['weekday'],
                'period' => $place['period']->value,
                'zoneId' => $place['zoneId'],
                'teacherId' => $pick->teacherId,
                'fixed' => false,
            ];
            $this->take($pick->teacherId, $place['weekday'], $place['period'], $place['zoneId'], $place['weight'], $load, $perBreak, $perDay, $zonesHeld, $inCell);
        }

        // Back into reading order for whatever displays the draft: the engine filled heaviest-first,
        // which is an implementation detail nobody should have to see.
        usort($placed, static fn (array $a, array $b): int => [$a['period'], $a['weekday'], $a['zoneId']] <=> [$b['period'], $b['weekday'], $b['zoneId']]);
        usort($unfilled, static fn (array $a, array $b): int => [$a['period'], $a['weekday'], $a['zoneId']] <=> [$b['period'], $b['weekday'], $b['zoneId']]);

        return new BreakRotaProposal($placed, $unfilled, $this->perTeacher($eligible, $load, $perBreak));
    }

    /**
     * The places still to fill, heaviest zone first, with the hand-pinned ones taken out.
     *
     * Weekday and zone break ties so two equally demanding places always come out in the same order and
     * the whole proposal stays reproducible.
     *
     * @param list<array{weekday: int, period: BreakPeriod, zoneId: int, weight: int}>    $places the week's places
     * @param list<array{weekday: int, period: BreakPeriod, zoneId: int, teacherId: int}> $fixed  the hand-pinned ones
     *
     * @return list<array{weekday: int, period: BreakPeriod, zoneId: int, weight: int}> the places to fill
     */
    private function heaviestFirst(array $places, array $fixed): array
    {
        $taken = [];
        foreach ($fixed as $place) {
            $key = $place['weekday'].':'.$place['period']->value.':'.$place['zoneId'];
            $taken[$key] = ($taken[$key] ?? 0) + 1;
        }

        $todo = [];
        foreach ($places as $place) {
            $key = $place['weekday'].':'.$place['period']->value.':'.$place['zoneId'];
            if (($taken[$key] ?? 0) > 0) {
                --$taken[$key];
                continue;
            }
            $todo[] = $place;
        }

        usort($todo, static fn (array $a, array $b): int => [$b['weight'], $a['period']->value, $a['weekday'], $a['zoneId']] <=> [$a['weight'], $b['period']->value, $b['weekday'], $b['zoneId']]);

        return $todo;
    }

    /**
     * The best candidate for one place, or null when there is none.
     *
     * @param list<BreakRotaCandidate>                                  $candidates the eligible teachers
     * @param array{weekday: int, period: BreakPeriod, zoneId: int, weight: int} $place the place to fill
     * @param array<int, int>                                           $load       teacher id → weight carried
     * @param array<int, array<string, int>>                            $perBreak   teacher id → recreo → places held
     * @param array<int, array<int, int>>                               $perDay     teacher id → weekday → places held
     * @param array<int, array<int, bool>>                              $zonesHeld  teacher id → zone id → held
     * @param array<string, array<int, bool>>                           $inCell     cell key → teacher ids already there
     *
     * @return BreakRotaCandidate|null the pick
     */
    private function best(array $candidates, array $place, array $load, array $perBreak, array $perDay, array $zonesHeld, array $inCell): ?BreakRotaCandidate
    {
        $cell = $place['weekday'].':'.$place['period']->value;
        $best = null;
        $bestScore = null;

        foreach ($candidates as $candidate) {
            $id = $candidate->teacherId;
            if (isset($inCell[$cell][$id])) {
                continue;
            }
            if (($perBreak[$id][$place['period']->value] ?? 0) >= $candidate->placesPerBreak()) {
                continue;
            }

            // Completar una guardia va PRIMERO, antes que la equidad del peso. Sin esto, con más
            // profesorado que plazas el motor le daba una plaza a cada uno para igualar la carga y no
            // emparejaba ni una: medido sobre el claustro real, 77 personas y 55 plazas salían 0 guardias
            // y 55 medias. Una guardia solo existe si hay pareja, así que primero se cierran las que hay
            // a medias y después se reparte lo demás.
            $mine = $perBreak[$id][$place['period']->value] ?? 0;
            $other = $perBreak[$id][$this->otherBreak($place['period'])->value] ?? 0;

            $score = [
                $other > $mine ? 0 : 1,
                $load[$id] ?? 0,
                $mine,
                isset($zonesHeld[$id][$place['zoneId']]) ? 1 : 0,
                $perDay[$id][$place['weekday']] ?? 0,
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
     * Tells apart the ways a place goes unfilled, because they call for different answers: everybody at
     * their quota is fixed by raising quotas, everybody already standing at that very recreo means the cell
     * needs more people than there are on the rota at all, and an empty candidate list means the course has
     * no rota to draw from yet.
     *
     * The empty list is checked FIRST and on purpose: with no candidates the loop below never runs and
     * every gap fell through to {@see GAP_NOBODY_LEFT}, blaming a crowd that does not exist.
     *
     * @param list<BreakRotaCandidate>                                  $candidates the eligible teachers
     * @param array{weekday: int, period: BreakPeriod, zoneId: int, weight: int} $place the place
     * @param array<int, array<string, int>>                            $perBreak   teacher id → recreo → places held
     * @param array<string, array<int, bool>>                           $inCell     cell key → teacher ids already there
     *
     * @return string one of the GAP_* reasons
     */
    private function whyNobody(array $candidates, array $place, array $perBreak, array $inCell): string
    {
        if ([] === $candidates) {
            return self::GAP_NOBODY_ELIGIBLE;
        }

        $cell = $place['weekday'].':'.$place['period']->value;
        foreach ($candidates as $candidate) {
            if (!isset($inCell[$cell][$candidate->teacherId])) {
                return self::GAP_QUOTA_EXHAUSTED;
            }
        }

        return self::GAP_NOBODY_LEFT;
    }

    /**
     * The other recreo of the day — the one a place at this recreo would pair with.
     *
     * @param BreakPeriod $period the recreo
     *
     * @return BreakPeriod the other one
     */
    private function otherBreak(BreakPeriod $period): BreakPeriod
    {
        return BreakPeriod::FIRST === $period ? BreakPeriod::SECOND : BreakPeriod::FIRST;
    }

    /**
     * Books a place against a teacher's counters.
     *
     * @param int                             $teacherId the teacher
     * @param int                             $weekday   the weekday
     * @param BreakPeriod                     $period    the recreo
     * @param int                             $zoneId    the zone
     * @param int                             $weight    what the zone costs
     * @param array<int, int>                 $load      teacher id → weight carried
     * @param array<int, array<string, int>>  $perBreak  teacher id → recreo → places held
     * @param array<int, array<int, int>>     $perDay    teacher id → weekday → places held
     * @param array<int, array<int, bool>>    $zonesHeld teacher id → zone id → held
     * @param array<string, array<int, bool>> $inCell    cell key → teacher ids already there
     */
    private function take(int $teacherId, int $weekday, BreakPeriod $period, int $zoneId, int $weight, array &$load, array &$perBreak, array &$perDay, array &$zonesHeld, array &$inCell): void
    {
        $load[$teacherId] = ($load[$teacherId] ?? 0) + $weight;
        $perBreak[$teacherId][$period->value] = ($perBreak[$teacherId][$period->value] ?? 0) + 1;
        $perDay[$teacherId][$weekday] = ($perDay[$teacherId][$weekday] ?? 0) + 1;
        $zonesHeld[$teacherId][$zoneId] = true;
        $inCell[$weekday.':'.$period->value][$teacherId] = true;
    }

    /**
     * What a hand-pinned place costs, looked up from the week's demand by cell. Zero when the pinned
     * place is not in the demand at all — somebody was put in a cell nobody asked for, which is their
     * decision to make, but it should not distort the balance of everybody else.
     *
     * @param list<array{weekday: int, period: BreakPeriod, zoneId: int, weight: int}> $places the week's places
     * @param array{weekday: int, period: BreakPeriod, zoneId: int, teacherId: int}    $fixed  the pinned place
     *
     * @return int the weight
     */
    private function weightOf(array $places, array $fixed): int
    {
        foreach ($places as $place) {
            if ($place['zoneId'] === $fixed['zoneId']) {
                return $place['weight'];
            }
        }

        return 0;
    }

    /**
     * What each teacher ended up with: guardias (a long place paired with a short one), halves left over,
     * and the weight they carry. Heaviest first, because the table is there to show who is overloaded.
     *
     * @param list<BreakRotaCandidate>       $candidates the eligible teachers
     * @param array<int, int>                $load       teacher id → weight carried
     * @param array<int, array<string, int>> $perBreak   teacher id → recreo → places held
     *
     * @return list<array{teacherId: int, name: string, quota: int, guardias: int, halves: int, load: int}> the per-teacher load
     */
    private function perTeacher(array $candidates, array $load, array $perBreak): array
    {
        $rows = array_map(static function (BreakRotaCandidate $c) use ($load, $perBreak): array {
            $long = $perBreak[$c->teacherId][BreakPeriod::FIRST->value] ?? 0;
            $short = $perBreak[$c->teacherId][BreakPeriod::SECOND->value] ?? 0;

            return [
                'teacherId' => $c->teacherId,
                'name' => $c->name,
                'quota' => $c->quota,
                'guardias' => min($long, $short),
                'halves' => abs($long - $short),
                'load' => $load[$c->teacherId] ?? 0,
            ];
        }, $candidates);

        usort($rows, static fn (array $a, array $b): int => [$b['load'], mb_strtolower($a['name'])] <=> [$a['load'], mb_strtolower($b['name'])]);

        return $rows;
    }
}
