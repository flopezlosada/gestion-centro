<?php

declare(strict_types=1);

namespace App\Space;

use App\Entity\Room;
use App\Entity\ScheduleEntry;
use App\Entity\User;
use App\Enum\RoomSize;

/**
 * A lesson that has to go somewhere else: what the event threw out of its room, at one moment.
 *
 * The input of {@see RelocationSolver}. Deliberately a value object over plain data (plus the two
 * entities it points at) so the solver can be exercised without a database, the way
 * {@see \App\Guardia\GuardiaAssigner} is.
 */
final readonly class Displacement
{
    /**
     * @param \DateTimeImmutable  $date        the day
     * @param int                 $slotIndex   the period index
     * @param Room                $originRoom  the room it is being thrown out of
     * @param string|null         $groupNames  the group(s), ", "-separated
     * @param string|null         $subjectName the subject
     * @param User|null           $teacher     whose lesson it is
     * @param ScheduleEntry|null  $sourceEntry the timetable cell, while it exists
     */
    public function __construct(
        public \DateTimeImmutable $date,
        public int $slotIndex,
        public Room $originRoom,
        public ?string $groupNames = null,
        public ?string $subjectName = null,
        public ?User $teacher = null,
        public ?ScheduleEntry $sourceEntry = null,
    ) {
    }

    /**
     * The moment this displacement happens, as the key the solver indexes free rooms by.
     *
     * @return string "Y-m-d|slot"
     */
    public function moment(): string
    {
        return $this->date->format('Y-m-d').'|'.$this->slotIndex;
    }

    /**
     * How big the destination has to be, taken from the room they are being thrown out of: whatever
     * fitted there has to fit where they go.
     *
     * The centre measures rooms in GROUPS, not seats — nobody knows the enrolment of each group — so
     * this is the size criterion. Null when nobody has classified the origin room yet, and then size
     * rules nothing out.
     *
     * @return RoomSize|null the size needed, or null when unknown
     */
    public function sizeNeeded(): ?RoomSize
    {
        return $this->originRoom->getSize();
    }

    /**
     * How many people have to fit, when the origin room carries a headcount. Secondary to
     * {@see sizeNeeded()}: most rooms have no capacity filled in.
     *
     * @return int|null the seats needed, or null when unknown
     */
    public function seatsNeeded(): ?int
    {
        return $this->originRoom->getCapacity();
    }
}
