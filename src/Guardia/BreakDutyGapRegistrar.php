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
     * Records that a teacher's absence leaves their break duties unattended. Does nothing when the teacher
     * has no duty that weekday.
     *
     * A LIST, because a teacher can hold a place at each of the day's two recreos: being away leaves both
     * unwatched, and reporting only one would send the equipo directivo looking for half the volunteers
     * they need. Days already recorded come back as they are, so registering an absence in two goes never
     * alerts twice.
     *
     * ⚠️ It NEITHER flushes NOR alerts, on purpose. {@see BreakDutyGap} carries a UNIQUE on (duty, day), and
     * two people registering the same absence at the same instant can lose that race — so its INSERT has to
     * ride in the caller's own flush, not in one of its own afterwards. Otherwise the caller's "nothing was
     * written, send it again" recovery would be a lie: the absence and its covers would already be committed
     * while the collision came from a later flush. See {@see AbsenceRegistrar::register()} and
     * [[doctrine-flush-closes-em-trap]] — a failed flush closes the entity manager, so there is no retrying
     * in place, and the only honest recovery is for one flush to be the whole atomic boundary.
     *
     * The caller flushes and then calls {@see announce()} with the fresh gaps.
     *
     * @param AcademicYear       $year    the course the date falls into (whose rota to read)
     * @param User               $teacher the absent teacher
     * @param \DateTimeImmutable $date    the day of the absence
     *
     * @return array{gaps: list<BreakDutyGap>, fresh: list<BreakDutyGap>} every unattended recreo of that day,
     *                                                                   and the subset this pass created
     */
    public function record(AcademicYear $year, User $teacher, \DateTimeImmutable $date): array
    {
        $gaps = [];
        $fresh = [];
        foreach ($this->duties->findAllForTeacherAndWeekday($year, $teacher, Weekday::from((int) $date->format('N'))) as $duty) {
            $existing = $this->gaps->findForAssignmentAndDate($duty, $date);
            if (null !== $existing) {
                $gaps[] = $existing;
                continue;
            }

            $gap = (new BreakDutyGap())->setAssignment($duty)->setDate($date);
            $this->em->persist($gap);
            $gaps[] = $gap;
            $fresh[] = $gap;
        }

        return ['gaps' => $gaps, 'fresh' => $fresh];
    }

    /**
     * Alerts the leadership team about the recreos this pass has just left unattended. To be called only
     * AFTER the caller has committed them: the alert says "this is unattended", and it would be a lie if the
     * row that makes it true had not been written.
     *
     * @param list<BreakDutyGap> $fresh the gaps created in this pass ({@see record()})
     */
    public function announce(array $fresh): void
    {
        foreach ($fresh as $gap) {
            $this->notifier->notifyUncovered($gap);
        }
    }

    /**
     * The break duties a teacher has on a given day — what the "apuntar ausencia" screen asks so it can
     * offer the recreos as something the absence also affects, instead of guessing from the periods
     * ticked (a recreo is not one of them).
     *
     * @param AcademicYear       $year    the course the date falls into
     * @param User               $teacher the teacher
     * @param \DateTimeImmutable $date    the day
     *
     * @return BreakDutyAssignment[] their duties that day, earliest recreo first
     */
    public function dutiesOn(AcademicYear $year, User $teacher, \DateTimeImmutable $date): array
    {
        return $this->duties->findAllForTeacherAndWeekday($year, $teacher, Weekday::from((int) $date->format('N')));
    }
}
