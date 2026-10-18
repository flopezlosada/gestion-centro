<?php

declare(strict_types=1);

namespace App\Family;

use App\Enum\Weekday;
use App\Service\NotificationDispatcher;

/**
 * Tells the organiser of an activity when a family signs up for one of its sessions or withdraws, so they
 * know who to expect without going to look.
 *
 * A notice, not an e-mail: it lands in the bell and on the phone ({@see NotificationDispatcher}, where
 * "family." kinds are push-only by default), as the centre already complains about mail. It names no
 * family — the notice outlives the signup in the inbox, and the organiser sees who comes on the
 * activity's page anyway —, only the session and how many are coming now.
 */
final class FamilySignupNotifier
{
    public function __construct(private readonly NotificationDispatcher $dispatcher)
    {
    }

    /**
     * @param FamilySession $session the session someone just signed up for, already saved
     */
    public function signedUp(FamilySession $session): void
    {
        $this->notify($session, 'family.signup', 'Alguien se ha apuntado a «'.$session->activity->getTitle().'»');
    }

    /**
     * @param FamilySession $session the session someone just withdrew from, already saved
     */
    public function withdrew(FamilySession $session): void
    {
        $this->notify($session, 'family.withdrawal', 'Alguien ya no viene a «'.$session->activity->getTitle().'»');
    }

    /**
     * @param list<FamilySession> $sessions the sessions someone just signed up for at once, every week, already saved
     */
    public function signedUpEvery(array $sessions): void
    {
        $this->notifyEvery($sessions, 'family.signup', 'Alguien se ha apuntado a «%s» todas las semanas', 'Viene desde el %s (%s).');
    }

    /**
     * @param list<FamilySession> $sessions the sessions someone just withdrew from at once, already saved
     */
    public function withdrewEvery(array $sessions): void
    {
        $this->notifyEvery($sessions, 'family.withdrawal', 'Alguien ya no viene a «%s» ninguna semana', 'Deja de venir desde el %s (%s).');
    }

    /**
     * @param FamilySession $session the session
     * @param string        $kind    the notice kind
     * @param string        $title   the notice title
     */
    private function notify(FamilySession $session, string $kind, string $title): void
    {
        $coming = \count($session->signups());
        $wanted = $session->activity->getFamiliesWanted();

        $this->send($session, $kind, $title, self::dayOf($session)
            .': '.(1 === $coming ? 'viene 1 persona' : 'vienen '.$coming.' personas').(null === $wanted ? '' : ' de '.$wanted).'.');
    }

    /**
     * One notice for many sessions, from the first: a family pressing "every week" is one event to the
     * organiser, not a notice per week.
     *
     * @param list<FamilySession> $sessions the sessions, earliest first; none is nothing to tell
     * @param string              $kind     the notice kind
     * @param string              $title    the notice title, with %s for the activity
     * @param string              $body     the body, with %s for the first day and then for how many sessions
     */
    private function notifyEvery(array $sessions, string $kind, string $title, string $body): void
    {
        if ([] === $sessions) {
            return;
        }
        $count = \count($sessions);
        $this->send($sessions[0], $kind, \sprintf($title, $sessions[0]->activity->getTitle()), \sprintf($body, mb_strtolower(self::dayOf($sessions[0])), 1 === $count ? '1 sesión' : $count.' sesiones'));
    }

    /**
     * @param FamilySession $session the session the notice is about
     * @param string        $kind    the notice kind
     * @param string        $title   the notice title
     * @param string        $body    the notice body
     */
    private function send(FamilySession $session, string $kind, string $title, string $body): void
    {
        $organizer = $session->activity->getOrganizer();
        if (null !== $organizer) {
            $this->dispatcher->dispatch($organizer, $kind, $title, $body);
        }
    }

    /**
     * @return string the day of a session as the organiser reads it, e.g. "Martes 13/01"
     */
    private static function dayOf(FamilySession $session): string
    {
        return Weekday::from((int) $session->day->format('N'))->label().' '.$session->day->format('d/m');
    }
}
