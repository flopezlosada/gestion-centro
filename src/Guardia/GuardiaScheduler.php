<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Entity\AcademicYear;
use App\Entity\GuardiaCover;
use App\Entity\ScheduleEntry;
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
