<?php

declare(strict_types=1);

namespace App\Support;

use App\Util\CalendarDate;
use Symfony\Component\HttpFoundation\Request;

/**
 * What happens after a one-click "hecho" tick: where it sends you back to, and what it tells you. The
 * SINGLE definition of both, shared by the two toggles that offer a tick
 * ({@see \App\Controller\TaskController::toggleDone} and
 * {@see \App\Controller\PersonalEventController::toggleDone}), so ticking a task and ticking a reminder
 * can never disagree about where you end up nor about how the confirmation reads.
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

    /** The flash type the toast is rendered from ({@see templates/app_shell.html.twig}). */
    public const string FLASH = 'tick';

    /**
     * The toast payload for a tick that just happened: what was ticked, so the confirmation can name it,
     * and enough to offer "Deshacer" — which is the SAME toggle again, since a tick is reversible.
     *
     * Undoing does NOT offer its own undo button: the row is back where it was, visible, so the escape
     * hatch has served its purpose and a chain of undo-the-undo would only be a loop to get lost in.
     *
     * @param string  $kind  what was ticked, "task" or "event" (picks the toggle route in the template)
     * @param int     $id    the id of the ticked task/event
     * @param string  $title its title, to name it in the confirmation
     * @param bool    $done  the state it ended up in: true when just ticked, false when just undone
     * @param Request $request the tick's POST request, to carry the return day into the undo
     *
     * @return array{kind: string, id: int, title: string, done: bool, dia: string} the toast payload
     */
    public function flashFor(string $kind, int $id, string $title, bool $done, Request $request): array
    {
        return [
            'kind' => $kind,
            'id' => $id,
            'title' => $title,
            'done' => $done,
            // The undo must land where the tick did, so it carries the same day along — already
            // normalised here, so nothing but a real day (or nothing at all) ever reaches the template.
            'dia' => $this->dayFrom($request)?->format('Y-m-d') ?? '',
        ];
    }

    /**
     * The route to return to after a tick, as a route name plus its parameters.
     *
     * @param Request $request the tick's POST request
     *
     * @return array{0: string, 1: array<string, string>} the route name and its parameters
     */
    public function routeFor(Request $request): array
    {
        $day = $this->dayFrom($request);

        if (null === $day) {
            return ['app_homepage', []];
        }

        return ['calendar_index', ['vista' => 'dia', 'fecha' => $day->format('Y-m-d')]];
    }

    /**
     * The calendar day the tick came from, or null when it came from Inicio (or the field was tampered
     * with). Parsed, never trusted: anything that is not a real date reads as "no day".
     *
     * @param Request $request the tick's POST request
     *
     * @return \DateTimeImmutable|null midnight on that day, or null
     */
    private function dayFrom(Request $request): ?\DateTimeImmutable
    {
        return CalendarDate::parse(
            $request->request->getString(self::FIELD),
            new \DateTimeZone(date_default_timezone_get()),
        );
    }
}
