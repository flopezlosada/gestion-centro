<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Entity\AcademicYear;
use App\Entity\GuardiaCover;
use App\Entity\User;
use App\Enum\GuardiaDutyBand;
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
 * One rule it enforces on top of the pure ordering: a teacher who is themselves absent that period is
 * dropped from the pool — that is not a preference, it is impossible. Everyone else is offered to the
 * assigner along with how many groups they are ALREADY covering that period, and the assigner decides:
 * while there are enough people, nobody is double-booked; when the absences outnumber the guardia
 * teachers, somebody has to mind two groups and it goes to whoever carries least.
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

        $candidates = $this->candidates($year, $date, $slotIndex, $this->hereLoadByTeacher($parte));

        // One pick per uncovered group. In deficit the sequence repeats people, so a group is never
        // left with nobody just because the rota ran out.
        $picks = $this->assigner->sequence(\count($unassigned), $candidates);

        $newlyAssigned = [];
        foreach ($unassigned as $i => $cover) {
            if (!isset($picks[$i])) {
                break;
            }
            $cover->setAssignedGuardia($picks[$i]->teacher);
            $newlyAssigned[] = $cover;
        }
        $this->em->flush();

        foreach ($newlyAssigned as $cover) {
            $this->notifier->notifyAssigned($cover);
        }

        return \count($newlyAssigned);
    }

    /**
     * The teachers who can cover a group at a period, in the order the equitable engine would pick them
     * (whoever carries the least first). Same pool and same ordering the automatic split uses, so the
     * manual assignment sheet in the parte offers exactly what "repartir" would choose — including, in
     * deficit, the colleagues who would have to mind a second group.
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

        // How many covers are still open decides which bands open and whether doubling up is on the
        // table (see prioritise), so a sheet opened for one cover offers the same people the bulk split
        // would. Never below 1: with nothing open the list would come back empty and the sheet would
        // wrongly read as "nobody can cover".
        return $this->assigner->prioritise(
            max(1, $unassigned),
            $this->candidates($year, $date, $slotIndex, $this->hereLoadByTeacher($parte)),
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
     * Builds the pool of candidates for a period: the guardia and collaborator duty holders in the given
     * course, minus anyone absent that period, each with their cover balance and with how many groups
     * they are already covering right there.
     *
     * Whoever is already covering a group is NOT dropped here: they are handed over carrying their
     * {@code hereLoad} so the assigner can leave them out while there is anybody else, and reach for
     * them only in deficit. Deciding that is the pure engine's job, not this one's.
     *
     * @param AcademicYear       $year      the course whose timetable supplies the pool
     * @param \DateTimeImmutable $date      the day
     * @param int                $slotIndex the period index within the day
     * @param array<int, int>    $hereLoad  teacher id → groups already covered by them at this date and period
     *
     * @return list<GuardiaCandidate> the candidates who could cover
     */
    private function candidates(AcademicYear $year, \DateTimeImmutable $date, int $slotIndex, array $hereLoad): array
    {
        $weekday = Weekday::from((int) $date->format('N'));
        $absentIds = $this->covers->absentTeacherIdsAt($date, $slotIndex);
        $slotLoad = $this->covers->loadBySlot($slotIndex);
        $totalLoad = $this->covers->totalLoad();

        $candidates = [];
        $seen = [];
        foreach ($this->schedule->dutyPoolAt($year, $weekday, $slotIndex) as $entry) {
            $teacherId = $entry->getTeacher()->getId();
            if (null === $teacherId || \in_array($teacherId, $absentIds, true) || isset($seen[$teacherId])) {
                continue;
            }
            $seen[$teacherId] = true;
            $candidates[] = new GuardiaCandidate(
                $entry->getTeacher(),
                ScheduleActivityKind::COLLABORATOR === $entry->getKind() ? GuardiaDutyBand::COLLABORATOR : GuardiaDutyBand::GUARDIA,
                $slotLoad[$teacherId] ?? 0,
                $totalLoad[$teacherId] ?? 0,
                $hereLoad[$teacherId] ?? 0,
            );
        }

        return $candidates;
    }

    /**
     * How many groups each teacher is already covering in the given parte lines — 1 for the ordinary
     * case, more when they have already been doubled up. Read from the lines in memory, not from a
     * query: the caller has them loaded and they are the truth about this date and period.
     *
     * @param list<GuardiaCover> $parte the parte lines
     *
     * @return array<int, int> teacher id → groups they already cover at that date and period
     */
    private function hereLoadByTeacher(array $parte): array
    {
        $load = [];
        foreach ($parte as $cover) {
            $teacher = $cover->getAssignedGuardia();
            if (null !== $teacher && null !== $teacher->getId()) {
                $load[$teacher->getId()] = ($load[$teacher->getId()] ?? 0) + 1;
            }
        }

        return $load;
    }
}
