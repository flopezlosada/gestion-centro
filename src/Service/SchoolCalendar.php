<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\NonLectiveDayRepository;

/**
 * Answers whether a given day is a teaching day of the school calendar. A day is teaching (lective)
 * when it is neither a weekend nor a registered non-teaching day ({@see \App\Entity\NonLectiveDay}).
 *
 * This is the single source of truth for "may a task deadline fall here?" and for marking the
 * calendar grid.
 */
final class SchoolCalendar
{
    public function __construct(private readonly NonLectiveDayRepository $nonLectiveDays)
    {
    }

    /**
     * Whether the given day is a teaching day: not a weekend and not a registered non-teaching day.
     *
     * @param \DateTimeImmutable $date the day to check (time part is ignored)
     *
     * @return bool true if tasks may be due on that day
     */
    public function isLective(\DateTimeImmutable $date): bool
    {
        return !$this->isWeekend($date) && !$this->nonLectiveDays->existsOn($date);
    }

    /**
     * Whether the given day falls on a weekend (Saturday or Sunday), which is non-teaching by
     * definition and not stored as a {@see \App\Entity\NonLectiveDay}.
     *
     * @param \DateTimeImmutable $date the day to check
     *
     * @return bool true for Saturday or Sunday
     */
    public function isWeekend(\DateTimeImmutable $date): bool
    {
        // ISO-8601 day of week: 6 = Saturday, 7 = Sunday.
        return (int) $date->format('N') >= 6;
    }
}
