<?php

declare(strict_types=1);

namespace App\Space;

use App\Entity\SpacePlan;
use App\Entity\SpacePlanAssignment;
use App\Entity\SpacePlanOption;
use App\Entity\User;
use App\Enum\ProposalStrategy;
use App\Enum\SpacePlanStatus;
use App\Repository\SpacePlanAssignmentRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The three things that happen to a {@see SpacePlan} after it is written: alternatives are generated,
 * one is approved, or the whole thing is called off.
 *
 * Kept out of the controller because each has a rule that must hold wherever it is invoked from:
 * regenerating never destroys what a person edited by hand, approving refuses to double-book a room,
 * and cancelling takes a plan out of force without erasing what was announced.
 */
final class SpacePlanWorkflow
{
    public function __construct(
        private readonly RelocationProposer $proposer,
        private readonly SpacePlanAssignmentRepository $assignments,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Replaces the plan's generated alternatives with fresh ones and moves it to
     * {@see SpacePlanStatus::PROPOSED}.
     *
     * Hand-made options are kept: someone who built an alternative by hand, or reshaped a generated one,
     * has made a decision, and regenerating is not a reason to undo it. Only a plan that is still
     * editable can be regenerated — an approved plan is what the centre is doing, not a draft.
     *
     * @param SpacePlan $plan the plan to propose for
     *
     * @return list<SpacePlanOption> the alternatives now on the plan
     *
     * @throws \LogicException if the plan is no longer editable
     */
    public function generate(SpacePlan $plan): array
    {
        if (!$plan->getStatus()->isEditable()) {
            throw new \LogicException('Solo se pueden generar propuestas para un plan en borrador o en revisión.');
        }

        foreach ($plan->getOptions()->toArray() as $option) {
            if (!$this->wasTouchedByHand($option)) {
                if ($plan->getChosenOption() === $option) {
                    $plan->setChosenOption(null);
                }
                $plan->removeOption($option);
                $this->em->remove($option);
            }
        }

        $fresh = $this->proposer->propose($plan);
        foreach ($fresh as $option) {
            $this->em->persist($option);
        }

        $plan->setStatus(SpacePlanStatus::PROPOSED);
        $this->em->flush();

        return $fresh;
    }

    /**
     * Approves an alternative: from here on the plan overrides the ordinary timetable for its dates.
     *
     * Refuses when another approved plan already has one of these rooms at the same moment. Each plan
     * looked fine while it was being built — they are built against the timetable, not against each
     * other — so this is the only point where a double booking can be caught, and it is caught before
     * anybody is told rather than at the classroom door.
     *
     * @param SpacePlan       $plan     the plan
     * @param SpacePlanOption $option   the alternative to put in force
     * @param User            $approver who signs it
     *
     * @throws \LogicException if the option belongs to another plan, or a room clashes with a plan already in force
     */
    public function approve(SpacePlan $plan, SpacePlanOption $option, User $approver): void
    {
        if ($option->getPlan() !== $plan) {
            throw new \LogicException('Esa propuesta no es de este plan.');
        }

        $clashes = $this->clashes($plan, $option);
        if ([] !== $clashes) {
            throw new \LogicException(sprintf(
                'No se puede aprobar: %s. Cambia esas líneas a mano o anula el otro plan.',
                implode('; ', \array_slice($clashes, 0, 3)).(\count($clashes) > 3 ? sprintf(' y %d más', \count($clashes) - 3) : ''),
            ));
        }

        $plan->setChosenOption($option)
            ->setStatus(SpacePlanStatus::APPROVED)
            ->setApprovedBy($approver)
            ->setApprovedAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    /**
     * Takes a plan out of force. Nothing is deleted: what was announced and then called off is worth
     * more as a trail than as a gap.
     *
     * @param SpacePlan $plan the plan to call off
     */
    public function cancel(SpacePlan $plan): void
    {
        $plan->setStatus(SpacePlanStatus::CANCELLED);
        $this->em->flush();
    }

    /**
     * The double bookings an option would cause against the plans already in force, in words a person
     * can act on.
     *
     * @param SpacePlan       $plan   the plan being approved
     * @param SpacePlanOption $option the alternative
     *
     * @return list<string> one description per clash, empty when there is none
     */
    public function clashes(SpacePlan $plan, SpacePlanOption $option): array
    {
        $inForce = $this->assignments->inForceByRoomSlot($plan, $plan->getDateFrom(), $plan->getDateTo());
        if ([] === $inForce) {
            return [];
        }

        $clashes = [];
        foreach ($option->getAssignments() as $assignment) {
            $key = SpacePlanAssignmentRepository::key($assignment);
            $existing = '' === $key ? null : ($inForce[$key] ?? null);
            if (null === $existing) {
                continue;
            }

            $clashes[] = sprintf(
                '%s ya está ocupada el %s (hora %d) por «%s»',
                (string) $assignment->getRoom()?->getCode(),
                $assignment->getDate()->format('d/m/Y'),
                $assignment->getSlotIndex() + 1,
                $existing->getOption()->getPlan()->getTitle(),
            );
        }

        return $clashes;
    }

    /**
     * Whether a person has shaped this alternative — either they built it or they edited one of its
     * lines. What regenerating must leave alone.
     *
     * @param SpacePlanOption $option the alternative
     *
     * @return bool true when it carries a human decision
     */
    private function wasTouchedByHand(SpacePlanOption $option): bool
    {
        return ProposalStrategy::MANUAL === $option->getStrategy() || $option->isEdited();
    }

    /**
     * Records that a line was changed by hand, so regenerating will not undo it.
     *
     * The option keeps the strategy it was generated with, on purpose: knowing that it started as
     * "lo más cerca posible" and was then adjusted is more useful than relabelling it "hecha a mano" and
     * losing where it came from. The interface says both.
     *
     * @param SpacePlanAssignment $assignment the line that was edited
     */
    public function markEdited(SpacePlanAssignment $assignment): void
    {
        $assignment->setManuallyEdited(true);
    }
}
