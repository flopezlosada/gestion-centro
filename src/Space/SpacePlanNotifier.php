<?php

declare(strict_types=1);

namespace App\Space;

use App\Entity\SpacePlan;
use App\Entity\SpacePlanAssignment;
use App\Entity\User;
use App\Service\NotificationDispatcher;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tells the people an approved plan affects. Decides WHO and WHAT to say; the delivery (in-app notice +
 * e-mail + push) is {@see NotificationDispatcher}'s job, shared with every other notifier.
 *
 * ONE notice per person, listing THEIR lines. A week of exams can carry sixty lines, and sixty separate
 * notices to the same teacher is not information, it is noise that trains the claustro to ignore the
 * bell. The same reason drives {@see notifyChanges()}: after a plan has been announced, only the people
 * whose own lines moved hear about it again.
 *
 * Only an approved plan is announced. Announcing a draft would tell people to go somewhere the centre
 * has not decided on.
 */
final class SpacePlanNotifier
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Announces the plan to everyone it affects and records when it was done.
     *
     * @param SpacePlan $plan the approved plan
     *
     * @return int how many people were told
     *
     * @throws \LogicException if the plan is not approved, or has no chosen alternative
     */
    public function notify(SpacePlan $plan): int
    {
        if (!$plan->getStatus()->isInForce() || null === $plan->getChosenOption()) {
            throw new \LogicException('Solo se avisa de un plan aprobado.');
        }

        $told = 0;
        foreach ($this->byTeacher($plan->getChosenOption()->getAssignments()->toArray()) as $lines) {
            $teacher = $lines[0]->getTeacher();
            if (null === $teacher) {
                continue;
            }

            $this->dispatcher->dispatch(
                $teacher,
                'space.plan.published',
                sprintf('Cambio de aula: %s', $plan->getTitle()),
                $this->body($plan, $lines),
            );
            ++$told;
        }

        $plan->setNotifiedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $told;
    }

    /**
     * Tells only the people whose own lines changed after the plan was already announced.
     *
     * @param SpacePlan                 $plan    the approved plan
     * @param list<SpacePlanAssignment> $changed the lines that moved
     *
     * @return int how many people were told
     */
    public function notifyChanges(SpacePlan $plan, array $changed): int
    {
        $told = 0;
        foreach ($this->byTeacher($changed) as $lines) {
            $teacher = $lines[0]->getTeacher();
            if (null === $teacher) {
                continue;
            }

            $this->dispatcher->dispatch(
                $teacher,
                'space.plan.updated',
                sprintf('Cambio en «%s»', $plan->getTitle()),
                $this->body($plan, $lines, 'Ha cambiado lo que te afecta:'),
            );
            ++$told;
        }

        return $told;
    }

    /**
     * Groups lines by the person they concern, keeping every teacher's lines together and in order.
     *
     * @param list<SpacePlanAssignment> $assignments the lines
     *
     * @return array<int, list<SpacePlanAssignment>> teacher id → their lines
     */
    private function byTeacher(array $assignments): array
    {
        $byTeacher = [];
        foreach ($assignments as $assignment) {
            $teacherId = $assignment->getTeacher()?->getId();
            if (null !== $teacherId) {
                $byTeacher[$teacherId][] = $assignment;
            }
        }

        foreach ($byTeacher as &$lines) {
            usort($lines, static fn (SpacePlanAssignment $a, SpacePlanAssignment $b): int => [$a->getDate(), $a->getSlotIndex()] <=> [$b->getDate(), $b->getSlotIndex()]);
        }

        return $byTeacher;
    }

    /**
     * What one person is told: the centre's own reason, then their own lines, one per line. Concrete
     * enough to act on without opening the application — most people read the e-mail and nothing else.
     *
     * @param SpacePlan                 $plan   the plan
     * @param list<SpacePlanAssignment> $lines  that person's lines
     * @param string|null               $intro  an opening sentence, when it is not the first notice
     *
     * @return string the body
     */
    private function body(SpacePlan $plan, array $lines, ?string $intro = null): string
    {
        $parts = [];
        if (null !== $intro) {
            $parts[] = $intro;
        }
        if (null !== $plan->getPublicReason() && '' !== trim($plan->getPublicReason())) {
            $parts[] = $plan->getPublicReason();
        }

        foreach ($lines as $line) {
            $parts[] = sprintf(
                '%s, %d.ª hora: %s%s %s.',
                $line->getDate()->format('d/m/Y'),
                $line->getSlotIndex() + 1,
                $line->label(),
                null !== $line->getOriginRoomName() ? sprintf(' sale de %s y', $line->getOriginRoomName()) : '',
                null !== $line->getRoom() ? sprintf('pasa a %s', $line->getRoom()->getLabel()) : 'todavía SIN AULA asignada',
            );
        }

        return implode("\n", $parts);
    }

    /**
     * Everyone an approved plan concerns, so a screen can say how many people the announcement reaches
     * before it is sent.
     *
     * @param SpacePlan $plan the plan
     *
     * @return list<User> the affected people, without repeats
     */
    public function recipients(SpacePlan $plan): array
    {
        $option = $plan->getChosenOption();
        if (null === $option) {
            return [];
        }

        $people = [];
        foreach ($this->byTeacher($option->getAssignments()->toArray()) as $lines) {
            $teacher = $lines[0]->getTeacher();
            if (null !== $teacher) {
                $people[] = $teacher;
            }
        }

        return $people;
    }
}
