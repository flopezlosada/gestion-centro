<?php

declare(strict_types=1);

namespace App\Space;

use App\Entity\Room;
use App\Entity\ScheduleEntry;
use App\Entity\User;

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
     * How many people have to fit, inferred from the capacity of the room they are being thrown out of.
     *
     * The centre has no enrolment data (Peñalara exports none), so this is the only honest proxy
     * available: a group that fits in a 30-seat room needs about 30 seats. Null when the origin room's
     * capacity has not been filled in either — and then capacity cannot rule anything out.
     *
     * @return int|null the seats needed, or null when unknown
     */
    public function seatsNeeded(): ?int
    {
        return $this->originRoom->getCapacity();
    }
}
