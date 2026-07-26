<?php

declare(strict_types=1);

namespace App\Support;

use App\Util\CalendarDate;
use Symfony\Component\HttpFoundation\Request;

/**
 * Where a one-click "hecho" tick sends you back to. The SINGLE definition of that destination, shared
 * by the two toggles that offer it ({@see \App\Controller\TaskController::toggleDone} and
 * {@see \App\Controller\PersonalEventController::toggleDone}), so ticking a task and ticking a reminder
 * can never disagree about where you end up.
 *
 * A tick lives on two surfaces — Inicio and the calendar's day view — and must return to the one you
 * were on: landing on Inicio after ticking something in the calendar throws away the day you were
 * looking at.
 *
 * The form says where it came from with a plain DAY ("dia=YYYY-MM-DD"), never a URL or a route name:
 * this class turns that day back into a route it generates itself. So an open redirect is not merely
 * blocked, it is unrepresentable — the worst a tampered field can do is send you to a different day of
 * your own calendar.
 */
final class TickOutcome
{
    /** The form field carrying the calendar day to go back to; absent means "I came from Inicio". */
    public const string FIELD = 'dia';

    /**
     * The route to return to after a tick, as a route name plus its parameters.
     *
     * @param Request $request the tick's POST request
     *
     * @return array{0: string, 1: array<string, string>} the route name and its parameters
     */
    public function routeFor(Request $request): array
    {
        // Parsed, not trusted: anything that is not a real date falls back to Inicio.
        $day = CalendarDate::parse($request->request->getString(self::FIELD), new \DateTimeZone(date_default_timezone_get()));

        if (null === $day) {
            return ['app_homepage', []];
        }

        return ['calendar_index', ['vista' => 'dia', 'fecha' => $day->format('Y-m-d')]];
    }
}
