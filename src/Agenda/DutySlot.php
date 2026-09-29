<?php

declare(strict_types=1);

namespace App\Agenda;

use App\Enum\ScheduleActivityKind;

/**
 * One period a teacher's timetable puts them on duty — a guardia or a collaborator slot — on a
 * particular day: the standing "estás de guardia a 3ª", as opposed to a {@see \App\Entity\GuardiaCover},
 * which only exists once somebody is absent and this teacher is sent to cover them.
 *
 * Both are shown because a teacher reads them as the same thing: without the standing one, a day on
 * guardia with nobody absent looked like a day with no guardia at all.
 *
 * Timed with the cell's OWN clock times, not the course's teaching frame: Peñalara also puts guardias in
 * the recreos, which are not teaching periods, so the frame has no times for them.
 */
final readonly class DutySlot
{
    public function __construct(
        public ScheduleActivityKind $kind,
        public int $slotIndex,
        public bool $duringBreak,
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $endsAt,
    ) {
    }

    /**
     * How the slot is named to the teacher: "Guardia", or "Guardia de recreo" when it falls in a break.
     *
     * @return string the label
     */
    public function label(): string
    {
        return $this->kind->label().($this->duringBreak ? ' de recreo' : '');
    }
}
