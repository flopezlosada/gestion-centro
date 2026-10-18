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
     * @param FamilySession $session the session
     * @param string        $kind    the notice kind
     * @param string        $title   the notice title
     */
    private function notify(FamilySession $session, string $kind, string $title): void
    {
        $organizer = $session->activity->getOrganizer();
        if (null === $organizer) {
            return;
        }
        $coming = \count($session->signups());
        $wanted = $session->activity->getFamiliesWanted();

        $this->dispatcher->dispatch($organizer, $kind, $title, Weekday::from((int) $session->day->format('N'))->label().' '.$session->day->format('d/m')
            .': '.(1 === $coming ? 'viene 1 persona' : 'vienen '.$coming.' personas').(null === $wanted ? '' : ' de '.$wanted).'.');
    }
}
