<?php

declare(strict_types=1);

namespace App\Agenda;

use App\Enum\RecurrenceFrequency;

/**
 * Expands a personal event's recurrence into the concrete occurrence instants that will be
 * materialised as individual {@see \App\Entity\PersonalEvent} rows. Pure and side-effect free, so it
 * is unit-testable on its own.
 *
 * Weekly steps by seven days. Monthly keeps the start's day-of-month, clamped to each month's length
 * — "the 31st, monthly" lands on the last day of shorter months rather than overflowing into the
 * next one (the classic {@see \DateTimeImmutable::modify()} '+1 month' footgun). The time-of-day of
 * the start is preserved on every occurrence, and the start is always the first occurrence.
 */
final class RecurrenceExpander
{
    /** Hard safety cap on how many occurrences one series may materialise, whatever the range. */
    public const int MAX_OCCURRENCES = 400;

    /**
     * The occurrence instants of the recurrence, from $start to $until inclusive. Always includes
     * $start as the first occurrence (even a {@see RecurrenceFrequency::NONE} yields exactly it), so
     * the result is never empty. $until is treated as a whole day: an occurrence anywhere on that day
     * is kept, whatever the start's time-of-day.
     *
     * @param \DateTimeImmutable   $start     the first occurrence (its time-of-day is kept on all of them)
     * @param RecurrenceFrequency  $frequency how often it repeats
     * @param \DateTimeImmutable   $until     the last day to include (inclusive)
     *
     * @return list<\DateTimeImmutable> the occurrences, capped at {@see self::MAX_OCCURRENCES}
     */
    public function expand(\DateTimeImmutable $start, RecurrenceFrequency $frequency, \DateTimeImmutable $until): array
    {
        // Compare against the end of the day: $until arrives at midnight, but occurrences may carry a
        // time, and an occurrence later that same day must still count as "up to and including $until".
        $limit = $until->setTime(23, 59, 59);

        return match ($frequency) {
            RecurrenceFrequency::NONE => [$start],
            RecurrenceFrequency::WEEKLY => $this->weekly($start, $limit),
            RecurrenceFrequency::MONTHLY => $this->monthly($start, $limit),
        };
    }

    /**
     * @return list<\DateTimeImmutable>
     */
    private function weekly(\DateTimeImmutable $start, \DateTimeImmutable $limit): array
    {
        $occurrences = [$start];
        $cursor = $start->modify('+7 days');
        while ($cursor <= $limit && \count($occurrences) < self::MAX_OCCURRENCES) {
            $occurrences[] = $cursor;
            $cursor = $cursor->modify('+7 days');
        }

        return $occurrences;
    }

    /**
     * @return list<\DateTimeImmutable>
     */
    private function monthly(\DateTimeImmutable $start, \DateTimeImmutable $limit): array
    {
        $dayOfMonth = (int) $start->format('j');
        $occurrences = [$start];
        for ($month = 1; \count($occurrences) < self::MAX_OCCURRENCES; ++$month) {
            // Anchor on the first of each month (always valid), then clamp the day to that month's length.
            $base = $start->modify('first day of this month')->modify(\sprintf('+%d months', $month));
            $occurrence = $base->setDate((int) $base->format('Y'), (int) $base->format('n'), min($dayOfMonth, (int) $base->format('t')));
            if ($occurrence > $limit) {
                break;
            }
            $occurrences[] = $occurrence;
        }

        return $occurrences;
    }
}
