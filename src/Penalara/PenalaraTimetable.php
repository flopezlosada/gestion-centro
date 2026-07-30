<?php

declare(strict_types=1);

namespace App\Penalara;

/**
 * Everything a pair of Peñalara exports says about a course's timetable: the cells teachers occupy
 * ({@see $entries}) and the shape of the school day they sit in ({@see $frame}).
 *
 * The two travel together because they come from the same parse and the frame cannot be derived from
 * the entries: the recreos hold no activity, so the periods that matter most to the break duty rota are
 * exactly the ones no cell would reveal.
 */
final class PenalaraTimetable
{
    /**
     * @param list<ScheduleEntryDto> $entries        one per timetable cell (lective, guardia or collaborator)
     * @param list<TimeFrameSlotDto> $frame          the day's periods, one per index, earliest first
     * @param list<int>              $frameConflicts indices the export defined more than one way (different
     *                                               times or type on different weekdays); the first definition
     *                                               is the one kept, and these are reported rather than hidden
     */
    public function __construct(
        public readonly array $entries,
        public readonly array $frame,
        public readonly array $frameConflicts = [],
    ) {
    }
}
