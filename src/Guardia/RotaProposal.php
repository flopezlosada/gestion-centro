<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Enum\ScheduleActivityKind;

/**
 * What the rota engine came up with, and — just as importantly — what it could not do.
 *
 * The gaps are not an afterthought. On the centre's real numbers the quotas will often add up to less
 * than the week needs, and a proposal that quietly comes back short reads as a broken program instead of
 * as "nobody has been given enough guardias yet". So every place the engine failed to fill carries the
 * reason it failed, and every teacher left below their quota is named.
 */
final class RotaProposal
{
    /**
     * @param list<array{weekday: int, slot: int, teacherId: int, kind: string, fixed: bool}> $placements  who goes where
     * @param list<array{weekday: int, slot: int, kind: string, reason: string}>              $unfilled    the places nobody could take
     * @param list<array{teacherId: int, name: string, quota: int, assigned: int}>            $byTeacher   the load each teacher ended up with
     */
    public function __construct(
        public readonly array $placements,
        public readonly array $unfilled,
        public readonly array $byTeacher,
    ) {
    }

    /**
     * Headline figures for the report above the grid.
     *
     * `unfilledGuardias` exists so the screen can stop claiming that a shortage always lands on the
     * standby places. It normally does — the engine fills every period's guardias before handing out a
     * single support place — but "normally" is not "always": when the engine has nobody to place at all
     * the guardias go unfilled too, and the reassuring sentence then contradicts the figure right above
     * it. Counted here rather than in Twig because a template cannot filter a list by kind without
     * turning into logic.
     *
     * `placedByEngine` is what publishing would actually write: the fixed places are already in the
     * timetable and {@see RotaPlanner::publish()} skips them. It exists so the screen offering the button
     * and the code performing the write agree on whether there is anything to publish — with guardias in
     * the Peñalara export and no quota typed, `placed` is not zero while the write is empty, and the
     * screen would offer an action that is then refused.
     *
     * @return array{placed: int, placedByEngine: int, unfilled: int, unfilledGuardias: int, needed: int, atQuota: int, belowQuota: int, unusedQuota: int} the summary
     */
    public function summary(): array
    {
        $atQuota = 0;
        $belowQuota = 0;
        $unusedQuota = 0;
        foreach ($this->byTeacher as $row) {
            if ($row['assigned'] >= $row['quota']) {
                ++$atQuota;
                continue;
            }
            ++$belowQuota;
            $unusedQuota += $row['quota'] - $row['assigned'];
        }

        $unfilledGuardias = \count(array_filter(
            $this->unfilled,
            static fn (array $gap): bool => ScheduleActivityKind::GUARDIA->value === $gap['kind'],
        ));

        $placedByEngine = \count(array_filter(
            $this->placements,
            static fn (array $place): bool => !$place['fixed'],
        ));

        return [
            'placed' => \count($this->placements),
            'placedByEngine' => $placedByEngine,
            'unfilled' => \count($this->unfilled),
            'unfilledGuardias' => $unfilledGuardias,
            'needed' => \count($this->placements) + \count($this->unfilled),
            'atQuota' => $atQuota,
            'belowQuota' => $belowQuota,
            'unusedQuota' => $unusedQuota,
        ];
    }

    /**
     * Why the week came up short, in the terms a human can act on: nobody has been given a quota yet,
     * or there was nobody free at all, or there were people free but all of them had used up their
     * quota. The three call for different responses — type the quota table, redraw the timetable, or
     * raise somebody's quota — so they are never merged. See the GAP_* constants of
     * {@see RotaProposer}.
     *
     * @return array<string, int> reason → how many places it accounts for
     */
    public function gapsByReason(): array
    {
        $reasons = [];
        foreach ($this->unfilled as $gap) {
            $reasons[$gap['reason']] = ($reasons[$gap['reason']] ?? 0) + 1;
        }

        return $reasons;
    }
}
