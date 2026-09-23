<?php

declare(strict_types=1);

namespace App\Agenda;

/**
 * Lays out a day's {@see TimelineBlock}s on an hourly grid, Google-Calendar-style: each block's top and
 * height are a percentage of the grid's visible window, and blocks that overlap in time share their row
 * side by side instead of stacking on top of each other unreadably.
 *
 * Pure positioning — it knows nothing about classes, guardias or meetings, only time spans. The caller
 * builds the blocks (routes, labels, colours) and shares one window across every day of a week, so the
 * hour rows line up from one column to the next.
 */
final class DayTimeline
{
    private const int MIN_START_HOUR = 8;
    private const int MAX_END_HOUR = 21;

    /** Shortest a block is ever drawn, so a 5-minute guardia handover is still readable, not a sliver. */
    private const int MIN_HEIGHT_MINUTES = 20;

    /**
     * The grid's vertical window for a set of days' blocks: the earliest start (rounded down to the
     * hour) to the latest end (rounded up), widened to a sensible school-day range — {@see MIN_START_HOUR}
     * to {@see MAX_END_HOUR} — so a light day doesn't render a single cramped hour.
     *
     * @param list<TimelineBlock> $blocks every block the grid will show, across all its days
     * @param \DateTimeImmutable  $day    any day in the grid, to anchor the window's date part
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} the window start and end
     */
    public function windowFor(array $blocks, \DateTimeImmutable $day): array
    {
        $startMinutes = self::MIN_START_HOUR * 60;
        $endMinutes = self::MAX_END_HOUR * 60;
        foreach ($blocks as $block) {
            $startMinutes = min($startMinutes, $this->minutesOfDay($block->startsAt));
            $endMinutes = max($endMinutes, $this->minutesOfDay($block->endsAt));
        }

        $startMinutes = intdiv($startMinutes, 60) * 60;
        $endMinutes = min((int) (60 * ceil($endMinutes / 60)), 23 * 60);

        return [
            $day->setTime(intdiv($startMinutes, 60), $startMinutes % 60),
            $day->setTime(intdiv($endMinutes, 60), $endMinutes % 60),
        ];
    }

    /**
     * The hour marks to label down the side of the grid, from the window's start to its end.
     *
     * @param \DateTimeImmutable $windowStart the grid's first visible instant
     * @param \DateTimeImmutable $windowEnd   the grid's last visible instant
     *
     * @return list<array{label: string, top: float}> each hour's label and top offset (percent of the window)
     */
    public function hourMarks(\DateTimeImmutable $windowStart, \DateTimeImmutable $windowEnd): array
    {
        $marks = [];
        for ($hour = $windowStart; $hour < $windowEnd; $hour = $hour->modify('+1 hour')) {
            $marks[] = ['label' => $hour->format('H:i'), 'top' => $this->percentOf($hour, $windowStart, $windowEnd)];
        }

        return $marks;
    }

    /**
     * Positions every block within the window: its column layout (side by side with whatever it
     * overlaps) and its top/height/left/width as percentages of the grid.
     *
     * @param list<TimelineBlock> $blocks      the day's blocks
     * @param \DateTimeImmutable  $windowStart the grid's first visible instant
     * @param \DateTimeImmutable  $windowEnd   the grid's last visible instant
     *
     * @return list<array{block: TimelineBlock, top: float, height: float, left: float, width: float}> one entry per block
     */
    public function layout(array $blocks, \DateTimeImmutable $windowStart, \DateTimeImmutable $windowEnd): array
    {
        usort($blocks, static fn (TimelineBlock $a, TimelineBlock $b): int => $a->startsAt <=> $b->startsAt ?: $a->endsAt <=> $b->endsAt);

        $positioned = [];
        foreach ($this->overlapGroups($blocks) as $group) {
            $columns = $this->packColumns($group);
            $columnCount = 1 + max($columns);
            foreach ($group as $i => $block) {
                $top = $this->percentOf($block->startsAt, $windowStart, $windowEnd);
                $bottom = $this->percentOf($this->atLeast($block, self::MIN_HEIGHT_MINUTES), $windowStart, $windowEnd);
                $positioned[] = [
                    'block' => $block,
                    'top' => $top,
                    'height' => max(0.0, $bottom - $top),
                    // Cast explícito: division entera exacta en PHP devuelve int, no float, y rompería
                    // la forma declarada (y un assertSame estricto en el test).
                    'left' => (float) ($columns[$i] / $columnCount * 100),
                    'width' => (float) (100 / $columnCount),
                ];
            }
        }

        return $positioned;
    }

    /**
     * Splits blocks (already sorted by start) into clusters that transitively overlap: a block belongs to
     * the current cluster as long as it starts before the cluster's furthest end so far. Each cluster is
     * laid out in its own columns, independently of how busy the rest of the day is.
     *
     * @param list<TimelineBlock> $sorted the blocks, sorted by start
     *
     * @return list<list<TimelineBlock>> the clusters
     */
    private function overlapGroups(array $sorted): array
    {
        $groups = [];
        $current = [];
        $currentEnd = null;
        foreach ($sorted as $block) {
            if ([] !== $current && $block->startsAt >= $currentEnd) {
                $groups[] = $current;
                $current = [];
                $currentEnd = null;
            }
            $current[] = $block;
            $currentEnd = null === $currentEnd ? $block->endsAt : max($currentEnd, $block->endsAt);
        }
        if ([] !== $current) {
            $groups[] = $current;
        }

        return $groups;
    }

    /**
     * Greedy column assignment within one overlap cluster: each block takes the first column whose last
     * block already ended by the time this one starts, or a new column when none is free.
     *
     * @param list<TimelineBlock> $group one overlap cluster, sorted by start
     *
     * @return array<int, int> the block's index (within $group) → its column
     */
    private function packColumns(array $group): array
    {
        $columnEnds = [];
        $columnOf = [];
        foreach ($group as $i => $block) {
            $chosen = null;
            foreach ($columnEnds as $column => $end) {
                if ($end <= $block->startsAt) {
                    $chosen = $column;
                    break;
                }
            }
            $chosen ??= \count($columnEnds);
            $columnEnds[$chosen] = $block->endsAt;
            $columnOf[$i] = $chosen;
        }

        return $columnOf;
    }

    /**
     * A block's end time, pulled forward to guarantee at least the minimum visible height — the block's
     * OWN end still shows in its label, only the drawn box is floored.
     */
    private function atLeast(TimelineBlock $block, int $minMinutes): \DateTimeImmutable
    {
        $shortest = $block->startsAt->modify("+{$minMinutes} minutes");

        return max($block->endsAt, $shortest);
    }

    /**
     * Where a given instant falls in the window, as a percentage from the top — the "now" line uses this
     * directly; blocks and hour marks go through {@see layout()} and {@see hourMarks()} instead.
     *
     * @return float|null the percentage, or null when the instant falls outside the window
     */
    public function percentInWindow(\DateTimeImmutable $at, \DateTimeImmutable $windowStart, \DateTimeImmutable $windowEnd): ?float
    {
        return $at >= $windowStart && $at <= $windowEnd ? $this->percentOf($at, $windowStart, $windowEnd) : null;
    }

    private function minutesOfDay(\DateTimeImmutable $at): int
    {
        return ((int) $at->format('H')) * 60 + (int) $at->format('i');
    }

    private function percentOf(\DateTimeImmutable $at, \DateTimeImmutable $windowStart, \DateTimeImmutable $windowEnd): float
    {
        $total = $windowEnd->getTimestamp() - $windowStart->getTimestamp();
        if ($total <= 0) {
            return 0.0;
        }

        $offset = $at->getTimestamp() - $windowStart->getTimestamp();

        // Cast explícito: cuando la división es exacta PHP devuelve int, no float, y max()/min() solo
        // lo corrigen por casualidad en los extremos (0 o 100) por su regla de empate "gana el primero".
        return max(0.0, min(100.0, (float) ($offset / $total * 100)));
    }
}
