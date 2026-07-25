<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Entity\AcademicYear;
use App\Entity\GuardiaCover;
use App\Entity\ScheduleEntry;
use App\Entity\User;
use App\Enum\ScheduleActivityKind;
use App\Enum\Weekday;
use App\Repository\GuardiaCoverRepository;
use App\Repository\ScheduleEntryRepository;
use App\Service\GuardiaAssignmentNotifier;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ties the equitable {@see GuardiaAssigner} to the database: it reads the guardia pool and the
 * cover balance (assigned covers with no incident) for a date and period, and fills the
 * still-unassigned parte lines.
 *
 * Two rules it enforces on top of the pure ordering: a teacher who is themselves absent that period
 * is dropped from the pool, and a teacher already covering another group that period is not offered
 * again (one teacher covers at most one group per hour).
 */
final class GuardiaScheduler
{
    public function __construct(
        private readonly ScheduleEntryRepository $schedule,
        private readonly GuardiaCoverRepository $covers,
        private readonly GuardiaAssigner $assigner,
        private readonly EntityManagerInterface $em,
        private readonly GuardiaAssignmentNotifier $notifier,
    ) {
    }

    /**
     * Assigns a guardia teacher to every unassigned cover on a date and period, balancing the load.
     * Leaves a cover unassigned when the pool runs out. The pool is read from the given course's
     * timetable (the course the date falls into).
     *
     * @param AcademicYear       $year      the course whose timetable supplies the guardia pool
     * @param \DateTimeImmutable $date      the day
     * @param int                $slotIndex the period index within the day
     *
     * @return int how many covers were newly assigned
     */
    public function autoAssign(AcademicYear $year, \DateTimeImmutable $date, int $slotIndex): int
    {
        $parte = $this->covers->findForParte($date, $slotIndex);
        $unassigned = array_values(array_filter($parte, static fn (GuardiaCover $c): bool => null === $c->getAssignedGuardia()));
        if ([] === $unassigned) {
            return 0;
        }

        $takenTeacherIds = $this->assignedTeacherIds($parte);
        $candidates = $this->candidates($year, $date, $slotIndex, $takenTeacherIds);

        $ordered = $this->assigner->prioritise(\count($unassigned), $candidates);

        $newlyAssigned = [];
        foreach ($unassigned as $i => $cover) {
            if (!isset($ordered[$i])) {
                break;
            }
            $cover->setAssignedGuardia($ordered[$i]->teacher);
            $newlyAssigned[] = $cover;
        }
        $this->em->flush();

        foreach ($newlyAssigned as $cover) {
            $this->notifier->notifyAssigned($cover);
        }

        return \count($newlyAssigned);
    }

    /**
     * The teachers who can still cover a group at a period, in the order the equitable engine would
     * pick them (whoever carries the least first). Same pool and same ordering the automatic split
     * uses, so the manual assignment sheet in the parte offers exactly what "repartir" would choose.
     *
     * @param AcademicYear       $year      the course whose timetable supplies the guardia pool
     * @param \DateTimeImmutable $date      the day
     * @param int                $slotIndex the period index within the day
     * @param list<GuardiaCover> $parte     the parte lines already loaded for that date and period
     *
     * @return list<GuardiaCandidate> the available teachers, least loaded first
     */
    public function availableFor(AcademicYear $year, \DateTimeImmutable $date, int $slotIndex, array $parte): array
    {
        $unassigned = \count(array_filter($parte, static fn (GuardiaCover $c): bool => null === $c->getAssignedGuardia()));

        // How many covers are still open decides whether collaborators join at all (see prioritise),
        // so a sheet opened for one cover offers the same people the bulk split would.
        return $this->assigner->prioritise(
            max(1, $unassigned),
            $this->candidates($year, $date, $slotIndex, $this->assignedTeacherIds($parte)),
        );
    }

    /**
     * Assigns one teacher to one still-uncovered parte line, as the automatic split does: no change
     * reason is recorded (this is the initial assignment, not an edit) and the substitute is notified.
     *
     * Both refusals below are the point of this method rather than a formality, because the caller
     * takes the teacher from a submitted form: the page was rendered from a pool that was correct then,
     * and by the time the coordinator clicks, that teacher may have been marked absent or given another
     * group, or the cover may already have been filled by "repartir" in another tab.
     *
     * @param AcademicYear       $year    the course whose timetable supplies the guardia pool
     * @param GuardiaCover       $cover   the parte line to cover, which must still be uncovered
     * @param User               $teacher the teacher who will cover it, who must still be available
     * @param list<GuardiaCover> $parte   the parte lines for that date and period
     *
     * @throws AssignmentRefused when the cover is already assigned or the teacher is no longer eligible
     */
    public function assign(AcademicYear $year, GuardiaCover $cover, User $teacher, array $parte): void
    {
        if (null !== $cover->getAssignedGuardia()) {
            // Changing an assignment already made is an edit, and an edit carries a mandatory reason
            // into the audit log — that is what the modify screen is for.
            throw AssignmentRefused::alreadyCovered($cover);
        }

        $elegibles = array_map(
            static fn (GuardiaCandidate $c): ?int => $c->teacher->getId(),
            $this->availableFor($year, $cover->getDate(), $cover->getSlotIndex(), $parte),
        );
        if (!\in_array($teacher->getId(), $elegibles, true)) {
            throw AssignmentRefused::notAvailable($teacher);
        }

        $cover->setAssignedGuardia($teacher);
        $this->em->flush();
        $this->notifier->notifyAssigned($cover);
    }

    /**
     * Builds the pool of candidates for a period: guardia and collaborator duty holders in the given
     * course, minus anyone absent that period and anyone already covering a group then, each with
     * their cover balance.
     *
     * @param AcademicYear       $year            the course whose timetable supplies the pool
     * @param \DateTimeImmutable $date            the day
     * @param int                $slotIndex       the period index within the day
     * @param list<int>          $takenTeacherIds ids already covering a group this period
     *
     * @return list<GuardiaCandidate> the available candidates
     */
    private function candidates(AcademicYear $year, \DateTimeImmutable $date, int $slotIndex, array $takenTeacherIds): array
    {
        $weekday = Weekday::from((int) $date->format('N'));
        $absentIds = $this->covers->absentTeacherIdsAt($date, $slotIndex);
        $slotLoad = $this->covers->loadBySlot($slotIndex);
        $totalLoad = $this->covers->totalLoad();
        $excluded = array_merge($absentIds, $takenTeacherIds);

        $candidates = [];
        $seen = [];
        foreach ($this->schedule->dutyPoolAt($year, $weekday, $slotIndex) as $entry) {
            $teacherId = $entry->getTeacher()->getId();
            if (null === $teacherId || \in_array($teacherId, $excluded, true) || isset($seen[$teacherId])) {
                continue;
            }
            $seen[$teacherId] = true;
            $candidates[] = new GuardiaCandidate(
                $entry->getTeacher(),
                ScheduleActivityKind::COLLABORATOR === $entry->getKind(),
                $slotLoad[$teacherId] ?? 0,
                $totalLoad[$teacherId] ?? 0,
            );
        }

        return $candidates;
    }

    /**
     * The ids of teachers already assigned to a cover in the given parte lines.
     *
     * @param list<GuardiaCover> $parte the parte lines
     *
     * @return list<int> the assigned teachers' ids
     */
    private function assignedTeacherIds(array $parte): array
    {
        $ids = [];
        foreach ($parte as $cover) {
            $teacher = $cover->getAssignedGuardia();
            if (null !== $teacher && null !== $teacher->getId()) {
                $ids[] = $teacher->getId();
            }
        }

        return $ids;
    }
}
