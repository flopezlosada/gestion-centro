<?php

declare(strict_types=1);

namespace App\Guardia;

/**
 * Pure, dependency-free statistics over the guardia covers, for the coordinator's analytics dashboard.
 * It never touches the database: it takes the lightweight rows and rankings the repository already
 * produced and derives the descriptive measures (equity of the split, monthly evolution, weekday ×
 * period heatmap). Kept separate from the controller so the maths is unit-testable in isolation.
 */
final class GuardiaStatistics
{
    /** Spanish three-letter month abbreviations, index 1–12. */
    private const MONTHS = [1 => 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    /**
     * Fairness of the guardia split across the teachers who did at least one. Reports the classic
     * descriptive measures plus a Gini coefficient (0 = everyone did the same, 1 = one person did all)
     * turned into a plain-language label, so a reader sees at a glance whether the rota is balanced.
     *
     * @param list<int> $totals guardias covered per teacher (only teachers with ≥1)
     *
     * @return array{count: int, mean: float, median: float, min: int, max: int, spread: int, gini: float, label: string}
     */
    public function equity(array $totals): array
    {
        $count = \count($totals);
        if (0 === $count) {
            return ['count' => 0, 'mean' => 0.0, 'median' => 0.0, 'min' => 0, 'max' => 0, 'spread' => 0, 'gini' => 0.0, 'label' => '—'];
        }

        sort($totals);
        $sum = array_sum($totals);
        $mean = $sum / $count;
        $mid = intdiv($count, 2);
        $median = 0 === $count % 2 ? ($totals[$mid - 1] + $totals[$mid]) / 2 : (float) $totals[$mid];
        $min = $totals[0];
        $max = $totals[$count - 1];
        $gini = $this->gini($totals, $sum);

        return [
            'count' => $count,
            'mean' => round($mean, 1),
            'median' => round($median, 1),
            'min' => $min,
            'max' => $max,
            'spread' => $max - $min,
            'gini' => round($gini, 2),
            'label' => $this->balanceLabel($gini),
        ];
    }

    /**
     * Absences per calendar month, oldest first, split into covered / incident / unassigned so a
     * stacked bar reads as the month's total. Empty months (no absences) simply do not appear.
     *
     * @param list<array{date: \DateTimeImmutable, slot: int, assigned: bool, incident: bool}> $rows the analytics rows
     *
     * @return list<array{key: string, label: string, absences: int, covered: int, incidents: int, unassigned: int}>
     */
    public function byMonth(array $rows): array
    {
        $buckets = [];
        foreach ($rows as $row) {
            $key = $row['date']->format('Y-m');
            $buckets[$key] ??= ['key' => $key, 'label' => self::MONTHS[(int) $row['date']->format('n')].' '.$row['date']->format('y'), 'absences' => 0, 'covered' => 0, 'incidents' => 0, 'unassigned' => 0];
            ++$buckets[$key]['absences'];
            if ($row['incident']) {
                ++$buckets[$key]['incidents'];
            } elseif ($row['assigned']) {
                ++$buckets[$key]['covered'];
            } else {
                ++$buckets[$key]['unassigned'];
            }
        }

        ksort($buckets);

        return array_values($buckets);
    }

    /**
     * Absences by weekday (1–5, Mon–Fri) × period, for a heatmap of when cover is needed. Returns the
     * grid plus the axes actually present, the per-cell max (to scale colour) and the row/column totals.
     *
     * @param list<array{date: \DateTimeImmutable, slot: int, assigned: bool, incident: bool}> $rows the analytics rows
     *
     * @return array{weekdays: list<int>, slots: list<int>, grid: array<int, array<int, int>>, max: int, weekdayTotals: array<int, int>, slotTotals: array<int, int>}
     */
    public function heatmap(array $rows): array
    {
        $grid = [];
        $slotsSeen = [];
        $weekdayTotals = [];
        $slotTotals = [];
        $max = 0;
        foreach ($rows as $row) {
            $weekday = (int) $row['date']->format('N');
            if ($weekday > 5) {
                continue; // guardias are Mon–Fri; ignore any stray weekend row
            }
            $slot = $row['slot'];
            $slotsSeen[$slot] = true;
            $grid[$weekday][$slot] = ($grid[$weekday][$slot] ?? 0) + 1;
            $weekdayTotals[$weekday] = ($weekdayTotals[$weekday] ?? 0) + 1;
            $slotTotals[$slot] = ($slotTotals[$slot] ?? 0) + 1;
            $max = max($max, $grid[$weekday][$slot]);
        }

        $slots = array_keys($slotsSeen);
        sort($slots);

        return [
            'weekdays' => [1, 2, 3, 4, 5],
            'slots' => $slots,
            'grid' => $grid,
            'max' => $max,
            'weekdayTotals' => $weekdayTotals,
            'slotTotals' => $slotTotals,
        ];
    }

    /**
     * Gini coefficient of a sorted, non-negative distribution (0 = perfect equality).
     *
     * @param list<int> $sorted ascending values
     * @param int       $sum    their sum (passed in to avoid recomputing)
     *
     * @return float the coefficient in [0, 1]
     */
    private function gini(array $sorted, int $sum): float
    {
        $n = \count($sorted);
        if (0 === $n || 0 === $sum) {
            return 0.0;
        }

        $weighted = 0;
        foreach ($sorted as $i => $value) {
            $weighted += ($i + 1) * $value;
        }

        return (2 * $weighted) / ($n * $sum) - ($n + 1) / $n;
    }

    /**
     * Plain-language reading of a Gini coefficient for the guardia rota.
     *
     * @param float $gini the coefficient
     *
     * @return string the label
     */
    private function balanceLabel(float $gini): string
    {
        return match (true) {
            $gini < 0.15 => 'Muy equilibrado',
            $gini < 0.30 => 'Equilibrado',
            $gini < 0.45 => 'Algo desigual',
            default => 'Desigual',
        };
    }
}
