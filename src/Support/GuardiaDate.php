<?php

declare(strict_types=1);

namespace App\Support;

use App\Util\CalendarDate;
use Symfony\Component\HttpFoundation\Request;

/**
 * The day a guardia screen is looking at, read from the request. Every surface of the module carries it
 * the same way — {@code ?date=YYYY-MM-DD} in the query for the views, a hidden field in the forms — so
 * the parsing lives here instead of once per controller, and the parte and its actions can never
 * disagree about which day they are on.
 *
 * Reuses {@see CalendarDate::parse()}, the app's single date parser: a bad value (or an overflow day
 * like 2026-02-30) falls back to today rather than silently rolling over into another month.
 */
final class GuardiaDate
{
    /**
     * The day the request is about, from the query string or the posted form, defaulting to today.
     *
     * @param Request $request the current request
     *
     * @return \DateTimeImmutable midnight on the day to show
     */
    public static function fromRequest(Request $request): \DateTimeImmutable
    {
        $raw = (string) ($request->query->get('date') ?? $request->request->get('date'));

        return CalendarDate::parse($raw, new \DateTimeZone(date_default_timezone_get())) ?? new \DateTimeImmutable('today');
    }
}
