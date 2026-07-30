<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BreakDutyGap;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;

/**
 * Alerts the equipo directivo that a recreo is going to be left unwatched, because the teacher on the
 * break duty rota is away that day.
 *
 * This notifier exists precisely because there is nothing to reassign: the centre has no spare staff at
 * break time, so the rule agreed with them is that the duty is NOT covered automatically and the
 * leadership team is told, in time to ask for a volunteer. The alert names the zone, the day and who is
 * missing — enough to act on from the notification itself.
 *
 * It fires once per gap, never once per absence edit: the caller ({@see \App\Guardia\BreakDutyGapRegistrar})
 * only reaches this after actually creating the {@see BreakDutyGap}, and a gap is unique per (duty, day).
 *
 * Delivery (in-app + push + e-mail) is {@see NotificationDispatcher}'s job, as with every other notifier.
 */
final class BreakDutyGapNotifier
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly UserRepository $users,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Notifies the leadership team about an unwatched recreo.
     *
     * @param BreakDutyGap $gap the gap just recorded (already flushed)
     *
     * @return int how many people were alerted
     */
    public function notifyUncovered(BreakDutyGap $gap): int
    {
        $leadership = $this->users->findLeadershipTeam();
        if ([] === $leadership) {
            // Nobody holds a centre-wide ranked role, so the alert has no addressee. Logged rather than
            // returned quietly: the screens will still say the recreo is unwatched, but a centre where
            // this happens has a configuration problem (no dirección assigned) that nothing else reports.
            $this->logger->warning('Recreo sin vigilar sin destinatarios: ningún usuario activo tiene un rol de equipo directivo.', [
                'gap' => $gap->getId(),
                'zone' => $gap->getAssignment()->getZone()->getName(),
                'date' => $gap->getDate()->format('Y-m-d'),
            ]);

            return 0;
        }

        $duty = $gap->getAssignment();
        $title = sprintf('Recreo sin vigilar: %s', $duty->getZone()->getName());
        $body = sprintf(
            '%s tiene la guardia de recreo de %s (%s, %s) y ese día falta. No se reasigna: hace falta un voluntario.',
            $duty->getTeacher()->getFullName(),
            $duty->getZone()->getName(),
            $duty->getWeekday()->label(),
            $gap->getDate()->format('d/m/Y'),
        );

        $notices = [];
        foreach ($leadership as $recipient) {
            $notices[] = $this->dispatcher->record($recipient, 'break_duty.uncovered', $title, $body);
        }
        // One flush for the whole leadership team rather than one per person.
        $this->dispatcher->flushAndSend($notices);

        return \count($notices);
    }
}
