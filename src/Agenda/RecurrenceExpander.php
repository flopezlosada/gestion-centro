<?php

declare(strict_types=1);

namespace App\Agenda;

/**
 * Expands a personal event's recurrence into the concrete occurrence instants that will be
 * materialised as individual {@see \App\Entity\PersonalEvent} rows. Pure and side-effect free, so it
 * is unit-testable on its own.
 *
 * Weekly steps by seven days. Monthly keeps the start's day-of-month, clamped to each month's length
 * — "the 31st, monthly" lands on the last day of shorter months rather than overflowing into the
 * next one (the classic {@see \DateTimeImmutable::modify()} '+1 month' footgun). The time-of-day of
 * the start is preserved on every occurrence.
 */
final class RecurrenceExpander
{
    public const string NONE = 'none';
    public const string WEEKLY = 'weekly';
    public const string MONTHLY = 'monthly';

    /** Hard safety cap on how many occurrences one series may materialise, whatever the range. */
    public const int MAX_OCCURRENCES = 400;

    /**
     * The occurrence instants of the recurrence, from $start to $until inclusive. A non-recurring
     * ({@see self::NONE}) or unknown frequency yields the single $start. Always returns at least one.
     *
     * @param \DateTimeImmutable $start     the first occurrence (its time-of-day is kept on all of them)
     * @param string             $frequency one of {@see self::NONE}, {@see self::WEEKLY}, {@see self::MONTHLY}
     * @param \DateTimeImmutable $until      the last day to include (inclusive)
     *
     * @return list<\DateTimeImmutable> the occurrences, capped at {@see self::MAX_OCCURRENCES}
     */
    public function expand(\DateTimeImmutable $start, string $frequency, \DateTimeImmutable $until): array
    {
        return match ($frequency) {
            self::WEEKLY => $this->weekly($start, $until),
            self::MONTHLY => $this->monthly($start, $until),
            default => [$start],
        };
    }

    /**
     * @return list<\DateTimeImmutable>
     */
    private function weekly(\DateTimeImmutable $start, \DateTimeImmutable $until): array
    {
        $occurrences = [];
        $cursor = $start;
        while ($cursor <= $until && \count($occurrences) < self::MAX_OCCURRENCES) {
            $occurrences[] = $cursor;
            $cursor = $cursor->modify('+7 days');
        }

        return $occurrences;
    }

    /**
     * @return list<\DateTimeImmutable>
     */
    private function monthly(\DateTimeImmutable $start, \DateTimeImmutable $until): array
    {
        $dayOfMonth = (int) $start->format('j');
        $occurrences = [];
        for ($month = 0; \count($occurrences) < self::MAX_OCCURRENCES; ++$month) {
            // Anchor on the first of each month (always valid), then clamp the day to that month's length.
            $base = $start->modify('first day of this month')->modify(\sprintf('+%d months', $month));
            $occurrence = $base->setDate((int) $base->format('Y'), (int) $base->format('n'), min($dayOfMonth, (int) $base->format('t')));
            if ($occurrence > $until) {
                break;
            }
            $occurrences[] = $occurrence;
        }

        return $occurrences;
    }
}
