<?php

declare(strict_types=1);

namespace App\Penalara;

use App\Enum\TimeSlotKind;

/**
 * One period of the centre's marco horario as the planificador declares it: its ordinal within the day,
 * its start and end times, and whether it is teaching time or a recreo.
 *
 * This is the shape of the day itself, independent of anybody's timetable — which is why it has to be
 * read from the planificador and not inferred from the cells teachers occupy: the two recreos carry no
 * activity at all, so a period nobody is busy in would otherwise be invisible to us.
 *
 * Times stay as the export's raw "HH:MM:SS" strings; converting them is the persistence layer's job.
 */
final class TimeFrameSlotDto
{
    /**
     * @param int          $index    the period's ordinal within the day (0-based Peñalara {@code indice})
     * @param string       $startsAt start time, "HH:MM:SS"
     * @param string       $endsAt   end time, "HH:MM:SS"
     * @param TimeSlotKind $kind     teaching period or recreo
     */
    public function __construct(
        public readonly int $index,
        public readonly string $startsAt,
        public readonly string $endsAt,
        public readonly TimeSlotKind $kind,
    ) {
    }
}
