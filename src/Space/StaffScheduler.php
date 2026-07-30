<?php

declare(strict_types=1);

namespace App\Space;

use App\Entity\SpacePlan;
use App\Entity\SpacePlanAssignment;
use App\Entity\SpacePlanOption;
use App\Entity\User;
use App\Enum\AssignmentKind;
use App\Enum\Weekday;
use App\Repository\ScheduleEntryRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Connects {@see StaffAssigner} to the database: works out who is in the centre at each moment of a
 * special day, hands the sessions over to be shared out, and writes the result onto the plan's lines.
 *
 * The split is the same one the guardia module uses ({@see \App\Guardia\GuardiaScheduler} over
 * {@see \App\Guardia\GuardiaAssigner}): the deciding is pure and testable, the reading and writing live
 * here.
 *
 * It only ever touches sessions that have NOBODY yet, unless asked to start over. Somebody who decided
 * by hand that a particular workshop is run by a particular person has made a decision, and re-running
 * the rota is not a reason to undo it.
 */
final class StaffScheduler
{
    public function __construct(
        private readonly StaffAssigner $assigner,
        private readonly ScheduleEntryRepository $schedule,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Shares out the sessions of an option among the staff.
     *
     * @param SpacePlan       $plan    the plan (supplies the course, the dates and the cap)
     * @param SpacePlanOption $option  the alternative whose sessions to cover
     * @param bool            $startOver when true, clears the existing rota first (hand-set lines included)
     *
     * @return array{assigned: int, uncovered: int, people: int} what the run did
     */
    public function share(SpacePlan $plan, SpacePlanOption $option, bool $startOver = false): array
    {
        $sessions = [];
        foreach ($option->getAssignments() as $assignment) {
            // Only activities are staffed: a relocated lesson already has its own teacher, the one whose
            // class it is.
            if (AssignmentKind::ACTIVITY !== $assignment->getKind()) {
                continue;
            }
            if ($startOver) {
                $assignment->setTeacher(null);
            }
            if (null === $assignment->getTeacher()) {
                $sessions[] = $assignment;
            }
        }

        if ([] === $sessions) {
            return ['assigned' => 0, 'uncovered' => 0, 'people' => 0];
        }

        $availability = $this->availability($plan, $sessions);
        $chosen = $this->assigner->assign(
            $sessions,
            $availability,
            $this->loadSoFar($option),
            $plan->getStaffQuota(),
            // The plan's own id: the same plan always yields the same rota, a different one falls on
            // different people.
            'plan:'.$plan->getId(),
        );

        $assigned = 0;
        $people = [];
        foreach ($chosen as $index => $teacher) {
            if (null === $teacher) {
                continue;
            }

            $sessions[$index]->setTeacher($teacher);
            ++$assigned;
            $people[(int) $teacher->getId()] = true;
        }
        $this->em->flush();

        return ['assigned' => $assigned, 'uncovered' => \count($sessions) - $assigned, 'people' => \count($people)];
    }

    /**
     * Who is in the centre at each moment the sessions happen.
     *
     * Read once per weekday, not once per session: a three-day plan asks the timetable three times,
     * however many workshops it carries.
     *
     * @param SpacePlan                 $plan     the plan
     * @param list<SpacePlanAssignment> $sessions the sessions to cover
     *
     * @return array<string, list<User>> who is available, keyed by "Y-m-d|slot"
     */
    private function availability(SpacePlan $plan, array $sessions): array
    {
        $everyone = $this->users->findAll();

        $boundsByWeekday = [];
        $availability = [];
        foreach ($sessions as $session) {
            $moment = StaffAssigner::moment($session);
            if (isset($availability[$moment])) {
                continue;
            }

            $weekday = Weekday::from((int) $session->getDate()->format('N'));
            $boundsByWeekday[$weekday->value] ??= $this->schedule->teachingDayBounds($plan->getAcademicYear(), $weekday);
            $bounds = $boundsByWeekday[$weekday->value];
            $slot = $session->getSlotIndex();

            $availability[$moment] = array_values(array_filter($everyone, static function (User $user) use ($bounds, $slot): bool {
                $own = $bounds[(int) $user->getId()] ?? null;

                // Somebody with no teaching at all that weekday is not in the building: they are not
                // offered. It is the same rule as "respect their usual timetable", at its limit.
                return null !== $own && $slot >= $own['from'] && $slot <= $own['to'];
            }));
        }

        return $availability;
    }

    /**
     * What each person is already carrying in this alternative, so a second run keeps sharing evenly
     * instead of piling everything on whoever was free.
     *
     * @param SpacePlanOption $option the alternative
     *
     * @return array<int, int> teacher id → sessions already theirs
     */
    private function loadSoFar(SpacePlanOption $option): array
    {
        $load = [];
        foreach ($option->getAssignments() as $assignment) {
            $teacherId = $assignment->getTeacher()?->getId();
            if (AssignmentKind::ACTIVITY === $assignment->getKind() && null !== $teacherId) {
                $load[$teacherId] = ($load[$teacherId] ?? 0) + 1;
            }
        }

        return $load;
    }
}
