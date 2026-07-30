<?php

declare(strict_types=1);

namespace App\Space;

use App\Entity\Room;
use App\Entity\ScheduleEntry;
use App\Entity\SpacePlanActivity;
use App\Entity\User;
use App\Enum\RoomSize;

/**
 * Something that needs a room at a given moment, and does not have one.
 *
 * Two things turn out to be the same problem, which is why they share this object:
 *  - a LESSON the event threw out of its room, which needs somewhere else to happen;
 *  - a WORKSHOP of a cultural day, which never had a room and needs one assigned.
 *
 * Both are "this many groups, at this moment, need a room at least this big". Treating them as one is
 * what lets the same solver place both, instead of a second engine for the cultural days.
 *
 * The input of {@see RelocationSolver}. Deliberately a value object over plain data (plus the entities
 * it points at) so the solver can be exercised without a database, the way
 * {@see \App\Guardia\GuardiaAssigner} is.
 */
final readonly class Displacement
{
    /**
     * @param \DateTimeImmutable      $date        the day
     * @param int                     $slotIndex   the period index
     * @param string                  $togetherKey what must end up in ONE room: everything sharing this key
     *                                             lands in the same place (a split group, a workshop)
     * @param Room|null               $originRoom  the room being left, when there is one
     * @param RoomSize|null           $sizeNeeded  how big the destination must be, when known
     * @param string|null             $groupNames  the group(s), ", "-separated
     * @param string|null             $subjectName the subject, for a lesson
     * @param string|null             $activityTitle what it is, for a workshop or an exam
     * @param User|null               $teacher     whose it is
     * @param ScheduleEntry|null      $sourceEntry the timetable cell it displaces, while it exists
     * @param SpacePlanActivity|null  $activity    the activity it materialises, for a workshop
     */
    public function __construct(
        public \DateTimeImmutable $date,
        public int $slotIndex,
        public string $togetherKey,
        public ?Room $originRoom = null,
        public ?RoomSize $sizeNeeded = null,
        public ?string $groupNames = null,
        public ?string $subjectName = null,
        public ?string $activityTitle = null,
        public ?User $teacher = null,
        public ?ScheduleEntry $sourceEntry = null,
        public ?SpacePlanActivity $activity = null,
    ) {
    }

    /**
     * A lesson thrown out of its room. What it needs is whatever fitted where it was.
     *
     * @param \DateTimeImmutable $date       the day
     * @param int                $slotIndex  the period index
     * @param Room               $originRoom the room being left
     * @param ScheduleEntry      $entry      the timetable cell
     *
     * @return self the displacement
     */
    public static function fromLesson(\DateTimeImmutable $date, int $slotIndex, Room $originRoom, ScheduleEntry $entry): self
    {
        return new self(
            date: $date,
            slotIndex: $slotIndex,
            // Everyone thrown out of one room at one moment goes to one room: a desdoble must not be
            // split in half across the building.
            togetherKey: 'room:'.$originRoom->getId(),
            originRoom: $originRoom,
            sizeNeeded: $originRoom->getSize(),
            groupNames: $entry->getGroupName(),
            subjectName: $entry->getSubjectName(),
            teacher: $entry->getTeacher(),
            sourceEntry: $entry,
        );
    }

    /**
     * A workshop (or any activity) that has no room yet. What it needs comes from how many groups are
     * going to it, or from the size the person asked for.
     *
     * @param \DateTimeImmutable $activityDate the day
     * @param int                $slotIndex    the period index
     * @param SpacePlanActivity  $activity     the activity to place
     *
     * @return self the displacement
     */
    public static function forActivity(\DateTimeImmutable $activityDate, int $slotIndex, SpacePlanActivity $activity): self
    {
        $groups = $activity->getTargetGroupNames();

        return new self(
            date: $activityDate,
            slotIndex: $slotIndex,
            // Each session of a workshop is placed on its own: the same workshop can run in different
            // rooms at different hours without anybody minding.
            togetherKey: 'activity:'.$activity->getId().':'.$slotIndex,
            // The size comes from how many groups are going: a workshop for two groups needs a room for
            // two groups. With no groups listed, nothing is inferred and size rules nothing out.
            sizeNeeded: [] === $groups ? null : RoomSize::forGroups(\count($groups)),
            groupNames: [] === $groups ? null : implode(', ', $groups),
            activityTitle: $activity->getTitle(),
            activity: $activity,
        );
    }

    /**
     * The moment this happens, as the key the solver indexes free rooms by.
     *
     * @return string "Y-m-d|slot"
     */
    public function moment(): string
    {
        return $this->date->format('Y-m-d').'|'.$this->slotIndex;
    }

    /**
     * How many people have to fit, when the origin room carries a headcount. Secondary to
     * {@see $sizeNeeded}: most rooms have no seat count filled in, and a workshop has none at all.
     *
     * @return int|null the seats needed, or null when unknown
     */
    public function seatsNeeded(): ?int
    {
        return $this->originRoom?->getCapacity() ?? $this->activity?->getRequiredCapacity();
    }
}
