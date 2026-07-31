<?php

declare(strict_types=1);

namespace App\Guardia;

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
     * @return array{placed: int, unfilled: int, needed: int, atQuota: int, belowQuota: int, unusedQuota: int} the summary
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

        return [
            'placed' => \count($this->placements),
            'unfilled' => \count($this->unfilled),
            'needed' => \count($this->placements) + \count($this->unfilled),
            'atQuota' => $atQuota,
            'belowQuota' => $belowQuota,
            'unusedQuota' => $unusedQuota,
        ];
    }

    /**
     * Why the week came up short, in the terms a human can act on: either there was nobody free at all,
     * or there were people free but all of them had used up their quota. The two call for opposite
     * responses — redraw the timetable, or raise somebody's quota — so they are never merged.
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
