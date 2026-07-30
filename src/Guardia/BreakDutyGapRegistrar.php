<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Entity\AcademicYear;
use App\Entity\BreakDutyAssignment;
use App\Entity\BreakDutyGap;
use App\Entity\User;
use App\Enum\Weekday;
use App\Repository\BreakDutyAssignmentRepository;
use App\Repository\BreakDutyGapRepository;
use App\Service\BreakDutyGapNotifier;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What happens to a recreo when the teacher on the rota is away: nothing is reassigned — the centre has
 * nobody spare at break time — so the day is recorded as a {@see BreakDutyGap} and the equipo directivo
 * is alerted to look for a volunteer.
 *
 * Idempotent by design, which is the whole point of recording the gap rather than just firing an alert:
 * an absence is routinely registered more than once for the same day (a couple of periods in the
 * morning, the whole day once it is confirmed), and each pass comes back through here. The second pass
 * finds the existing gap and nobody gets a duplicate alert.
 *
 * Deliberately NOT wired into {@see AbsenceRegistrar}'s cover loop: a recreo is not a period of the
 * timetable anybody teaches, so it produces no {@see \App\Entity\GuardiaCover} and takes no part in the
 * substitution split. It is a separate consequence of the same absence, resolved once per day.
 */
final class BreakDutyGapRegistrar
{
    public function __construct(
        private readonly BreakDutyAssignmentRepository $duties,
        private readonly BreakDutyGapRepository $gaps,
        private readonly BreakDutyGapNotifier $notifier,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Records that a teacher's absence leaves their break duty unattended, and alerts the leadership team.
     * Does nothing when the teacher has no duty that weekday, or when the day is already recorded.
     *
     * @param AcademicYear       $year    the course the date falls into (whose rota to read)
     * @param User               $teacher the absent teacher
     * @param \DateTimeImmutable $date    the day of the absence
     *
     * @return BreakDutyGap|null the gap (fresh or already recorded), or null when there is no duty to miss
     */
    public function register(AcademicYear $year, User $teacher, \DateTimeImmutable $date): ?BreakDutyGap
    {
        $duty = $this->duties->findForTeacherAndWeekday($year, $teacher, Weekday::from((int) $date->format('N')));
        if (null === $duty) {
            return null;
        }

        $existing = $this->gaps->findForAssignmentAndDate($duty, $date);
        if (null !== $existing) {
            return $existing;
        }

        $gap = (new BreakDutyGap())->setAssignment($duty)->setDate($date);
        $this->em->persist($gap);
        $this->em->flush();

        // Only after the gap is committed: the alert says "this is unattended", and it would be a lie if
        // the row that makes it true had not been written.
        $this->notifier->notifyUncovered($gap);

        return $gap;
    }

    /**
     * Whether a teacher has a break duty on a given day — what the "apuntar ausencia" screen asks so it
     * can offer the recreo as something the absence also affects, instead of guessing from the periods
     * ticked (a recreo is not one of them).
     *
     * @param AcademicYear       $year    the course the date falls into
     * @param User               $teacher the teacher
     * @param \DateTimeImmutable $date    the day
     *
     * @return BreakDutyAssignment|null their duty that day, or null when they have none
     */
    public function dutyOn(AcademicYear $year, User $teacher, \DateTimeImmutable $date): ?BreakDutyAssignment
    {
        return $this->duties->findForTeacherAndWeekday($year, $teacher, Weekday::from((int) $date->format('N')));
    }
}
