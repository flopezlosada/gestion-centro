<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\BreakDutyAssignment;
use App\Repository\BreakDutyAssignmentRepository;

/**
 * Tells each teacher what break duties they have for the course, the day the leadership team announces the
 * rota.
 *
 * It is the last step of the circuit the centre asked for — propose, validate or modify, send it to the
 * people affected — and the reason publishing no longer means "everybody sees it": until this runs, the rota
 * is a draft that can be retouched without sixty people having written down a reparto that was going to
 * change.
 *
 * One notice per person with THEIR places, not one per place: a fixed rota of two recreos a week would mean
 * four notices to the same person on the same afternoon, which is how a channel stops being read.
 */
final class BreakRotaAnnouncer
{
    /** Machine kind; it belongs to the "guardias" topic ({@see \App\Enum\NotificationTopic}). */
    public const string KIND = 'break_duty.announced';

    public function __construct(
        private readonly BreakDutyAssignmentRepository $places,
        private readonly NotificationDispatcher $dispatcher,
    ) {
    }

    /**
     * Notifies everybody on the rota of the given course.
     *
     * @param AcademicYear $year the course whose rota has just been announced
     *
     * @return int how many people were notified
     */
    public function announce(AcademicYear $year): int
    {
        /** @var array<int, list<BreakDutyAssignment>> $byTeacher */
        $byTeacher = [];
        foreach ($this->places->findByYear($year) as $place) {
            $byTeacher[(int) $place->getTeacher()->getId()][] = $place;
        }
        if ([] === $byTeacher) {
            return 0;
        }

        $notifications = [];
        foreach ($byTeacher as $places) {
            $teacher = $places[0]->getTeacher();
            $lines = array_map(
                static fn (BreakDutyAssignment $p): string => sprintf(
                    '%s · %s · %s',
                    $p->getWeekday()->label(),
                    $p->getPeriod()->label(),
                    $p->getZone()->getName(),
                ),
                $places,
            );
            $notifications[] = $this->dispatcher->record(
                $teacher,
                self::KIND,
                sprintf('Tus guardias de recreo de %s', $year->getSchoolYear()),
                // El reparto entero en el propio aviso: es fijo para todo el curso, así que quien lo lee lo
                // quiere para apuntarlo, no para tener que entrar a buscarlo.
                implode("\n", $lines),
            );
        }
        $this->dispatcher->flushAndSend($notifications);

        return \count($notifications);
    }
}
