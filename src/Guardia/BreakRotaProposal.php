<?php

declare(strict_types=1);

namespace App\Guardia;

/**
 * What the break rota engine came up with, and what it could not do.
 *
 * The gaps matter more here than in the teaching rota, because one of them is arithmetic and nobody
 * would guess it: the week does not hold the same number of long and short places (the patio dirigido is
 * not watched at the short recreo), so some people end up with a place that cannot be paired into a
 * guardia however well anything is distributed. Reporting that as a HALF, with a name on it, is the
 * difference between "the program is odd" and "the demand does not add up".
 */
final class BreakRotaProposal
{
    /**
     * @param list<array{weekday: int, period: string, zoneId: int, teacherId: int, fixed: bool}> $places   who goes where
     * @param list<array{weekday: int, period: string, zoneId: int, reason: string}>              $unfilled the places nobody could take
     * @param list<array{teacherId: int, name: string, quota: int, guardias: int, halves: int, load: int}> $byTeacher what each teacher ended up with
     */
    public function __construct(
        public readonly array $places,
        public readonly array $unfilled,
        public readonly array $byTeacher,
    ) {
    }

    /**
     * Headline figures for the report above the grid.
     *
     * @return array{placed: int, unfilled: int, needed: int, guardias: int, halves: int, shortOfQuota: int} the summary
     */
    public function summary(): array
    {
        $guardias = 0;
        $halves = 0;
        $shortOfQuota = 0;
        foreach ($this->byTeacher as $row) {
            $guardias += $row['guardias'];
            $halves += $row['halves'];
            if ($row['guardias'] < $row['quota']) {
                ++$shortOfQuota;
            }
        }

        return [
            'placed' => \count($this->places),
            'unfilled' => \count($this->unfilled),
            'needed' => \count($this->places) + \count($this->unfilled),
            'guardias' => $guardias,
            'halves' => $halves,
            'shortOfQuota' => $shortOfQuota,
        ];
    }

    /**
     * Why the week came up short, grouped by reason, so the screen can point at the right fix.
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
