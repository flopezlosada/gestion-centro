<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\HttpFoundation\Request;

/**
 * Reads the "which day and which period" a screen is looking at from the request.
 *
 * Shared by every screen built on the timetable grid (the guardia parte, the free-rooms consultation
 * and, later, the space plans): they all offer the same date picker and the same period tabs, and they
 * must all fall back the same way — an absent or unparseable date means today, an absent period means
 * the first of the day. Duplicating four lines per screen is how two of them end up disagreeing about
 * what a bad date means.
 */
final class SchedulePicker
{
    /**
     * The requested date, defaulting to today when absent or unparseable. Read from the query string
     * or the body, so a form that posts the day it is working on is understood too.
     *
     * @param Request $request the current request
     *
     * @return \DateTimeImmutable the date to show, at midnight
     */
    public static function date(Request $request): \DateTimeImmutable
    {
        $raw = (string) ($request->query->get('date') ?? $request->request->get('date'));
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

        return false !== $date ? $date : new \DateTimeImmutable('today');
    }

    /**
     * The requested period index, defaulting to the day's first period when absent or unknown.
     *
     * @param Request                                                                          $request the current request
     * @param list<array{index: int, startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}> $slots   the available periods
     *
     * @return int the period index to show
     */
    public static function slot(Request $request, array $slots): int
    {
        if ($request->query->has('slot')) {
            return (int) $request->query->get('slot');
        }

        return $slots[0]['index'] ?? 0;
    }
}
