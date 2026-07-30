<?php

declare(strict_types=1);

namespace App\Space;

use App\Entity\Room;
use App\Entity\SpacePlan;
use App\Entity\SpacePlanActivity;
use App\Entity\SpacePlanAssignment;
use App\Entity\SpacePlanOption;
use App\Enum\AssignmentKind;
use App\Enum\ProposalStrategy;
use App\Repository\ScheduleEntryRepository;
use App\Service\SchoolCalendar;

/**
 * Turns a plan's enunciado into whole alternatives: reads what the event occupies, works out which
 * lessons that throws out, asks {@see RelocationSolver} where each one goes under each criterion, and
 * builds one {@see SpacePlanOption} per criterion with the lines and the figures to compare them by.
 *
 * The database work lives here and the deciding lives in the solver, which is pure. That split is what
 * makes the criteria testable without fixtures — and it is the same shape the guardia module already
 * uses ({@see \App\Guardia\GuardiaScheduler} over {@see \App\Guardia\GuardiaAssigner}).
 *
 * It places two kinds of thing, and they turn out to be the same problem: a LESSON thrown out of its
 * room, and a WORKSHOP that never had one (the cultural days, where the equipo directivo brings the
 * timetable of workshops already decided and only the rooms are missing). Both are "this many groups, at
 * this moment, need a room at least this big", so one solver does both.
 *
 * Two things it does NOT do, said out loud rather than discovered later:
 *  - An activity with no DATE is left alone: deciding which day a workshop runs on is the centre's, not
 *    the program's. Give it a date and periods and it will find it a room.
 *  - It assigns rooms, never teachers. Sharing out who runs each workshop is the next piece.
 *
 * And it proposes; it never approves. Nothing it writes affects the effective timetable until a person
 * picks an option and approves the plan.
 */
final class RelocationProposer
{
    public function __construct(
        private readonly RoomOccupancy $occupancy,
        private readonly ScheduleEntryRepository $schedule,
        private readonly SchoolCalendar $calendar,
        private readonly RelocationSolver $solver,
    ) {
    }

    /**
     * Builds the alternatives for a plan. Two criteria that end up proposing exactly the same thing
     * yield ONE option that says so, instead of three cards a person has to compare to discover they
     * are identical.
     *
     * @param SpacePlan $plan the plan to propose for
     *
     * @return list<SpacePlanOption> the alternatives, best-known criterion first (never persisted here)
     */
    public function propose(SpacePlan $plan): array
    {
        $displacements = [];
        $freeByMoment = [];
        $fixed = [];

        foreach ($this->moments($plan) as [$date, $slotIndex]) {
            [$momentDisplacements, $free, $momentFixed] = $this->readMoment($plan, $date, $slotIndex);
            $displacements = [...$displacements, ...$momentDisplacements];
            $freeByMoment[$date->format('Y-m-d').'|'.$slotIndex] = $free;
            $fixed = [...$fixed, ...$momentFixed];
        }

        $options = [];
        $seen = [];
        $label = 'A';
        foreach (ProposalStrategy::generated() as $strategy) {
            $option = $this->buildOption($plan, $strategy, $this->solver->solve($displacements, $freeByMoment, $strategy), $fixed);

            // Same lines as an earlier criterion: keep one and say which criteria agreed, rather than
            // showing the same plan three times under three names.
            $fingerprint = $option->fingerprint();
            if (isset($seen[$fingerprint])) {
                $existing = $seen[$fingerprint];
                $existing->setRationale(rtrim($existing->getRationale(), '.').'. «'.$strategy->label().'» propone exactamente lo mismo.');
                // Off the plan's collection as well, or the cascade would persist the duplicate the
                // caller never sees.
                $plan->removeOption($option);
                continue;
            }

            $option->setLabel('Opción '.$label);
            $label = \chr(\ord($label) + 1);
            $seen[$fingerprint] = $option;
            $options[] = $option;
        }

        return $options;
    }

    /**
     * Every (date, period) the plan covers, skipping non-teaching days: proposing a room change for a
     * Saturday would be noise.
     *
     * @param SpacePlan $plan the plan
     *
     * @return list<array{0: \DateTimeImmutable, 1: int}> the moments, earliest first
     */
    private function moments(SpacePlan $plan): array
    {
        $slots = $this->schedule->distinctSlots($plan->getAcademicYear());

        $moments = [];
        foreach ($plan->dates() as $date) {
            if (!$this->calendar->isLective($date)) {
                continue;
            }

            foreach ($slots as $slot) {
                if ($plan->covers($date, $slot['index'])) {
                    $moments[] = [$date, $slot['index']];
                }
            }
        }

        return $moments;
    }

    /**
     * Reads one moment: what the event takes, what that throws out, and what is left free.
     *
     * Two subtleties the room occupancy alone cannot know, both of them the plan's doing:
     *  - a room whose only occupants are groups the plan takes out of the ordinary timetable (2º de
     *    Bachillerato during exam week) is FREE, and it is exactly the space the displaced lessons need;
     *  - a room the event itself takes is not free either, however empty the timetable leaves it.
     *
     * @param SpacePlan          $plan      the plan
     * @param \DateTimeImmutable $date      the day
     * @param int                $slotIndex the period index
     *
     * @return array{0: list<Displacement>, 1: list<Room>, 2: list<SpacePlanAssignment>} displacements,
     *                                                                                   free rooms and the event's own lines
     */
    private function readMoment(SpacePlan $plan, \DateTimeImmutable $date, int $slotIndex): array
    {
        $availability = $this->occupancy->at($plan->getAcademicYear(), $date, $slotIndex);

        // What the event takes here, as lines that will appear in every alternative.
        $blocked = [];
        $fixedLines = [];
        foreach ($plan->getActivities() as $activity) {
            $room = $activity->getRoom();
            if (null === $room || !$activity->occupies($room, $date, $slotIndex)) {
                continue;
            }

            $blocked[$room->getId()] = true;
            $fixedLines[] = $this->activityLine($activity, $room, $date, $slotIndex);
        }

        // Activities with a date and periods but NO room: workshops waiting for one. They compete for
        // the same free rooms as the displaced lessons, through the same solver.
        $displacements = [];
        foreach ($plan->getActivities() as $activity) {
            if (null !== $activity->getRoom() || $activity->getFixedDate()?->format('Y-m-d') !== $date->format('Y-m-d')) {
                continue;
            }
            if (\in_array($slotIndex, $activity->getFixedSlots(), true)) {
                $displacements[] = Displacement::forActivity($date, $slotIndex, $activity);
            }
        }

        $free = [];
        foreach ($availability->occupied as $occupation) {
            $roomId = $occupation->room->getId();

            // Everyone in this room is out of the ordinary timetable under this plan: the room is free.
            if ($this->allReplaced($plan, $occupation)) {
                if (!isset($blocked[$roomId]) && $occupation->room->isAssignable()) {
                    $free[] = $occupation->room;
                }
                continue;
            }

            if (!isset($blocked[$roomId])) {
                continue;
            }

            // One displacement per cell, so every teacher gets their own line and their own notice; the
            // solver keeps the cells of one room together.
            foreach ($occupation->entries as $entry) {
                if ($plan->replacesTimetableFor($entry->getGroupName())) {
                    continue;
                }

                $displacements[] = Displacement::fromLesson($date, $slotIndex, $occupation->room, $entry);
            }
        }

        foreach ($availability->free as $room) {
            if (!isset($blocked[$room->getId()]) && $room->isAssignable()) {
                $free[] = $room;
            }
        }

        return [$displacements, $free, $fixedLines];
    }

    /**
     * Whether every group in a room is one the plan takes out of the ordinary timetable.
     *
     * @param SpacePlan      $plan      the plan
     * @param RoomOccupation $occupation the room and its lessons
     *
     * @return bool true when nobody in it has an ordinary lesson under this plan
     */
    private function allReplaced(SpacePlan $plan, RoomOccupation $occupation): bool
    {
        foreach ($occupation->entries as $entry) {
            if (!$plan->replacesTimetableFor($entry->getGroupName())) {
                return false;
            }
        }

        return true;
    }

    /**
     * The line that states what the event itself occupies.
     *
     * @param SpacePlanActivity  $activity  the activity
     * @param Room               $room      the room it takes
     * @param \DateTimeImmutable $date      the day
     * @param int                $slotIndex the period index
     *
     * @return SpacePlanAssignment the line, not yet attached to an option
     */
    private function activityLine(SpacePlanActivity $activity, Room $room, \DateTimeImmutable $date, int $slotIndex): SpacePlanAssignment
    {
        return (new SpacePlanAssignment())
            ->setDate($date)
            ->setSlotIndex($slotIndex)
            ->setKind(AssignmentKind::ACTIVITY)
            ->setRoom($room)
            ->setActivityTitle($activity->getTitle())
            ->setGroupNames([] === $activity->getTargetGroupNames() ? null : implode(', ', $activity->getTargetGroupNames()));
    }

    /**
     * Assembles one alternative: the event's own lines plus a line per displaced lesson, with the
     * figures that make it comparable and a sentence a person can act on.
     *
     * @param SpacePlan                  $plan       the plan
     * @param ProposalStrategy           $strategy   the criterion followed
     * @param list<Placement>            $placements where each displaced lesson goes
     * @param list<SpacePlanAssignment>  $fixedLines the event's own lines
     *
     * @return SpacePlanOption the alternative
     */
    private function buildOption(SpacePlan $plan, ProposalStrategy $strategy, array $placements, array $fixedLines): SpacePlanOption
    {
        $option = (new SpacePlanOption())->setLabel('—')->setStrategy($strategy);
        $plan->addOption($option);

        foreach ($fixedLines as $line) {
            $line->copyTo($option);
        }

        $groups = [];
        $teachers = [];
        $specialised = 0;
        $unresolved = 0;
        foreach ($placements as $placement) {
            $displacement = $placement->displacement;
            $option->addAssignment((new SpacePlanAssignment())
                ->setDate($displacement->date)
                ->setSlotIndex($displacement->slotIndex)
                // A workshop that just got a room is an ACTIVITY, not a relocation: nothing was moved.
                ->setKind(null === $displacement->originRoom ? AssignmentKind::ACTIVITY : AssignmentKind::RELOCATION)
                ->setRoom($placement->room)
                ->setOriginRoomName($displacement->originRoom?->getCode())
                ->setGroupNames($displacement->groupNames)
                ->setSubjectName($displacement->subjectName)
                ->setActivityTitle($displacement->activityTitle)
                ->setTeacher($displacement->teacher)
                ->setSourceEntry($displacement->sourceEntry));

            if (null !== $displacement->groupNames) {
                $groups[$displacement->groupNames] = true;
            }
            if (null !== $displacement->teacher?->getId()) {
                $teachers[$displacement->teacher->getId()] = true;
            }
            if (!$placement->isResolved()) {
                ++$unresolved;
                continue;
            }
            if ($placement->room?->getKind()->isSpecialised() ?? false) {
                ++$specialised;
            }
        }

        $moved = \count(array_filter($placements, static fn (Placement $p): bool => null !== $p->displacement->originRoom));
        $option->setMetrics([
            'movedClasses' => $moved,
            'placedActivities' => \count($placements) - $moved,
            'affectedGroups' => \count($groups),
            'affectedTeachers' => \count($teachers),
            'specialisedRoomsUsed' => $specialised,
            'unresolved' => $unresolved,
        ]);

        return $option->setRationale($this->rationale($option));
    }

    /**
     * One sentence describing what an alternative costs, in the terms a person weighs: how much moves,
     * how many specialised spaces it eats, and whether anything is left homeless.
     *
     * @param SpacePlanOption $option the alternative
     *
     * @return string the sentence
     */
    private function rationale(SpacePlanOption $option): string
    {
        $moved = $option->metric('movedClasses');
        $placed = $option->metric('placedActivities');
        $parts = [];
        if ($moved > 0 || 0 === $placed) {
            $parts[] = sprintf('Mueve %d clase%s de %d grupo%s', $moved, 1 === $moved ? '' : 's', $option->metric('affectedGroups'), 1 === $option->metric('affectedGroups') ? '' : 's');
        }
        if ($placed > 0) {
            $parts[] = sprintf('coloca %d %s de actividad', $placed, 1 === $placed ? 'sesión' : 'sesiones');
        }

        if ($option->metric('specialisedRoomsUsed') > 0) {
            $parts[] = sprintf('ocupa %d espacio%s especializado%s', $option->metric('specialisedRoomsUsed'), 1 === $option->metric('specialisedRoomsUsed') ? '' : 's', 1 === $option->metric('specialisedRoomsUsed') ? '' : 's');
        }
        if ($option->hasUnresolved()) {
            $parts[] = sprintf('deja %d sin aula', $option->metric('unresolved'));
        }

        return implode('; ', $parts).'.';
    }
}
