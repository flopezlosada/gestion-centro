<?php

declare(strict_types=1);

namespace App\Space;

use App\Entity\Room;
use App\Entity\ScheduleEntry;

/**
 * One lesson as it really happens on a given date: the timetable cell, plus where it is actually held.
 *
 * The difference between the two is the whole point. The cell says "E1B, Inglés, 2IN5, every Tuesday";
 * an approved {@see \App\Entity\SpacePlan} can say "on Tuesday the 3rd, in 0LC7". Anything that
 * photographs a room — the guardia parte above all — has to write down the second one, or it sends a
 * covering teacher to a room the group is not in.
 */
final readonly class EffectiveLesson
{
    /**
     * @param ScheduleEntry $entry the ordinary timetable cell
     * @param Room|null     $room  the space an approved plan moved it to, or null when it stays put
     */
    public function __construct(
        public ScheduleEntry $entry,
        public ?Room $room = null,
    ) {
    }

    /**
     * Where the lesson is held: the space a plan moved it to, or the room the timetable names.
     *
     * @return string|null the room's short name, or null when nothing names one
     */
    public function roomName(): ?string
    {
        return $this->room?->getCode() ?? $this->entry->getRoomName();
    }

    /**
     * Whether an approved plan moved this lesson — what lets a screen say "aula cambiada" instead of
     * quietly showing a different room from the one the timetable shows.
     *
     * @return bool true when the room comes from a plan
     */
    public function isRelocated(): bool
    {
        return null !== $this->room;
    }
}
