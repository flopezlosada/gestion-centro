<?php

declare(strict_types=1);

namespace App\Agenda;

use App\Entity\LessonPlan;
use App\Entity\User;
use App\Repository\LessonPlanRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * «Correr las siguientes una clase»: when a class is left half done but the next classes with that group
 * were already planned, everything planned after it moves one class later and the class that frees up
 * carries on from the unfinished one. Without it a plan written ahead stays as it was, and the next class
 * opens on a topic the group has not reached.
 *
 * The move runs over the planned classes that come right after, one after another, and stops at the first
 * class with nothing planned: that gap absorbs the shift, so a plan further on, past a gap, keeps its day.
 * It moves CONTENT, not rows, walking from the last class back to the first so each one copies a class
 * not yet overwritten — rows keep their day and period, and the one-plan-per-class index never sees two
 * plans on the same class half way through.
 *
 * Only classes still to come are ever moved: a class already given is a record of what happened.
 */
final class LessonShift
{
    /** The first look ahead: a week and a day, where the next class with a group almost always is. */
    private const FIRST_WINDOW_DAYS = 8;
    /** Each further look ahead — past a two-week holiday. */
    private const WINDOW_DAYS = 28;
    /** How far ahead to look at all: a course. */
    private const HORIZON_DAYS = 330;

    public function __construct(
        private readonly MyClasses $myClasses,
        private readonly LessonPlanRepository $plans,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The planned class that the unfinished one would push later, if the teacher should be offered to
     * move them: this class left something pending, the next class with the group is planned, has not
     * started yet, and does not already carry on with the same topic.
     *
     * @param LessonPlan $plan the class just marked
     *
     * @return LessonPlan|null the next planned class, or null when there is nothing to offer
     */
    public function offer(LessonPlan $plan): ?LessonPlan
    {
        if (true !== $plan->getOutcome()?->leavesSomethingPending()) {
            return null;
        }

        $next = $this->nextClasses($plan, 1)[0] ?? null;
        if (null === $next || !$this->isStillToCome($next['date'], $next['class'])) {
            return null;
        }
        $nextPlan = $this->plans->findForClass($plan->getTeacher(), $next['date'], $next['class']->slotIndex);
        if (null === $nextPlan) {
            return null;
        }

        $carried = $nextPlan->getTopics()[0] ?? null;

        return null !== $plan->getTopic() && $carried?->getTopic() === $plan->getTopic() ? null : $nextPlan;
    }

    /**
     * Moves the planned classes after this one one class later, and has the first of them carry on from
     * this one. Does nothing unless {@see offer()} would offer it, so a stale button cannot move classes
     * that no longer need it.
     *
     * @param LessonPlan $plan the class left half done
     *
     * @return int how many planned classes moved, 0 when nothing did
     */
    public function shift(LessonPlan $plan): int
    {
        if (null === $this->offer($plan)) {
            return 0;
        }

        // The run of planned classes right after this one, and the free class that ends it.
        $run = [];
        $free = null;
        for ($wanted = 8; null === $free; $wanted *= 2) {
            $classes = $this->nextClasses($plan, $wanted);
            $planned = [] !== $classes
                ? $this->plans->findForTeacherBetween($plan->getTeacher(), $classes[0]['date'], end($classes)['date'])
                : [];
            $run = [];
            foreach ($classes as $next) {
                $existing = $planned[$next['date']->format('Y-m-d').'|'.$next['class']->slotIndex] ?? null;
                if (null === $existing) {
                    $free = $next;
                    break;
                }
                $run[] = $existing;
            }
            if (null === $free && \count($classes) < $wanted) {
                // The course ends before a free class: the last planned one has nowhere to go.
                return 0;
            }
        }

        if ([] === $run) {
            return 0;
        }

        $moved = new LessonPlan($plan->getTeacher(), $free['date'], $free['class']->slotIndex, $plan->getGroupNames(), $plan->getSubject(), $plan->getLevel());
        $this->entityManager->persist($moved->takeContentOf(end($run)));
        for ($i = \count($run) - 1; $i > 0; --$i) {
            $run[$i]->takeContentOf($run[$i - 1]);
        }
        $run[0]->continueFrom($plan);
        $this->entityManager->flush();

        return \count($run);
    }

    /**
     * The next classes with the same group and subject after this one, soonest first, as the timetable
     * really gives them — a non-teaching day has none. Same day and a later period counts.
     *
     * @param LessonPlan $plan  the class to start after
     * @param int        $count how many at most
     *
     * @return list<array{date: \DateTimeImmutable, class: ClassSession}> the classes
     */
    private function nextClasses(LessonPlan $plan, int $count): array
    {
        $found = [];
        $start = $plan->getDate();
        // Day by day the timetable costs a few queries, so the look ahead starts short and grows only when
        // the next class is further away (a holiday).
        for ($offset = 0, $span = self::FIRST_WINDOW_DAYS; $offset < self::HORIZON_DAYS && \count($found) < $count; $offset += $span, $span = self::WINDOW_DAYS) {
            $from = $start->modify(sprintf('+%d days', $offset));
            foreach ($this->myClasses->between($plan->getTeacher(), $from, $from->modify(sprintf('+%d days', $span - 1))) as $day => $sessions) {
                $date = new \DateTimeImmutable($day, $start->getTimezone());
                foreach ($sessions as $session) {
                    $later = $date > $start || $session->slotIndex > $plan->getSlotIndex();
                    if ($later && $plan->isSameClassAs($session->groupNames(), $session->subject())) {
                        $found[] = ['date' => $date, 'class' => $session];
                        if (\count($found) === $count) {
                            return $found;
                        }
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Whether a class has not started yet. Without a timetable frame the hour is unknown, so a class of
     * today counts as given.
     *
     * @param \DateTimeImmutable $date  its day
     * @param ClassSession       $class the class
     */
    private function isStillToCome(\DateTimeImmutable $date, ClassSession $class): bool
    {
        $now = $this->clock->now();

        return null !== $class->startsAt ? $class->startsAt > $now : $date > $now->setTime(0, 0);
    }
}
