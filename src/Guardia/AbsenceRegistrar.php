<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Entity\AcademicYear;
use App\Entity\GuardiaCover;
use App\Entity\User;
use App\Enum\Weekday;
use App\Repository\GuardiaCoverRepository;
use App\Repository\ScheduleEntryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Registers a teacher's absence in one step and lets the equitable engine take over: given the day
 * (and either specific periods or the teacher's whole teaching day), it creates a parte line for
 * each period the teacher actually teaches — a free period or a duty needs no cover — snapshotting
 * the uncovered group and room from the timetable, and then runs {@see GuardiaScheduler} for each
 * affected period (which assigns a guardia and notifies them).
 *
 * This is the single entry point behind both the coordinator's "apuntar ausencia" screen and a
 * teacher self-reporting their own absence, so the "register → auto-assign → notify" flow lives in
 * one place.
 */
final class AbsenceRegistrar
{
    public function __construct(
        private readonly ScheduleEntryRepository $schedule,
        private readonly GuardiaCoverRepository $covers,
        private readonly GuardiaScheduler $scheduler,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Registers the absence and runs the assignment.
     *
     * @param AcademicYear       $year        the course the date falls into (supplies the timetable)
     * @param User               $teacher     the absent teacher
     * @param \DateTimeImmutable $date        the day of the absence
     * @param list<int>|null     $slotIndexes the periods to register, or null for the whole teaching day
     * @param string|null        $taskNote    task/observations left for the groups (applied to every cover)
     *
     * @return AbsenceRegistrationResult what was created and what was skipped
     */
    public function register(AcademicYear $year, User $teacher, \DateTimeImmutable $date, ?array $slotIndexes, ?string $taskNote): AbsenceRegistrationResult
    {
        $weekday = Weekday::from((int) $date->format('N'));
        $slots = $slotIndexes ?? $this->schedule->lectiveSlotsFor($year, $teacher, $weekday);

        $createdSlots = [];
        $skippedFree = 0;
        $skippedExisting = 0;
        foreach (array_values(array_unique($slots)) as $slotIndex) {
            $lective = $this->schedule->lectiveAt($year, $teacher, $weekday, $slotIndex);
            if (null === $lective) {
                ++$skippedFree;
                continue;
            }
            if (null !== $this->covers->findOneBy(['absentTeacher' => $teacher, 'date' => $date, 'slotIndex' => $slotIndex])) {
                ++$skippedExisting;
                continue;
            }

            $this->em->persist((new GuardiaCover())
                ->setDate($date)
                ->setSlotIndex($slotIndex)
                ->setAbsentTeacher($teacher)
                ->setGroupName($lective->getGroupName())
                ->setRoomName($lective->getRoomName())
                ->setTaskNote($taskNote));
            $createdSlots[] = $slotIndex;
        }
        $this->em->flush();

        // Assign each affected period after the covers exist, so the balance sees them all.
        foreach ($createdSlots as $slotIndex) {
            $this->scheduler->autoAssign($year, $date, $slotIndex);
        }

        return new AbsenceRegistrationResult($createdSlots, $skippedFree, $skippedExisting);
    }
}
