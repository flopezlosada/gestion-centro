<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Agenda\DutySlot;
use App\Entity\GuardiaCover;
use App\Util\CalendarDate;

/**
 * A teacher's guardia WEEK: every duty period their timetable gives them, each one either empty or
 * filled with the covers assigned in it.
 *
 * It replaces two pieces of "mis guardias" that read badly together: a table of the standing hours that
 * never said whether anybody had to be covered in them, and a "hoy estás de guardia" card that was true
 * every single day for whoever has guardia every day. The week answers both at once. The calendar shows
 * the same periods too, but among classes and meetings; here they are alone.
 *
 * Covers and duty periods are matched by period index, as the calendar does: one duty period, one box.
 */
final readonly class TeacherGuardiaWeek
{
    /**
     * The Monday-to-Friday the screen shows: this week, or the next one on a weekend — on Saturday the
     * week that has just ended is no longer anything to plan for.
     *
     * @param \DateTimeImmutable $today today (midnight)
     *
     * @return list<\DateTimeImmutable> the five days, Monday first
     */
    public static function daysAround(\DateTimeImmutable $today): array
    {
        $monday = (int) $today->format('N') >= 6 ? $today->modify('next monday') : $today->modify('monday this week');

        return array_map(static fn (int $offset): \DateTimeImmutable => $monday->modify(\sprintf('+%d day', $offset)), range(0, 4));
    }

    /**
     * Builds the week.
     *
     * @param list<\DateTimeImmutable>                                                    $days        the days to show (see {@see daysAround()})
     * @param array<string, list<DutySlot>>                                               $dutiesByDay "Y-m-d" → the teacher's duty periods that day (see {@see \App\Agenda\MyClasses::dutiesBetween()})
     * @param GuardiaCover[]                                                              $covers      the covers assigned to the teacher over those days
     * @param array<int, array{startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}> $slotTimes   period times by slot index, for covers outside the duty periods
     * @param \DateTimeImmutable                                                          $today       today (midnight)
     * @param \DateTimeImmutable                                                          $now         the current instant
     *
     * @return list<array{date: \DateTimeImmutable, isToday: bool, slots: list<GuardiaWeekSlot>}> one entry per day, periods in order
     */
    public function forDays(array $days, array $dutiesByDay, array $covers, array $slotTimes, \DateTimeImmutable $today, \DateTimeImmutable $now): array
    {
        $coversByDaySlot = [];
        foreach ($covers as $cover) {
            $coversByDaySlot[$cover->getDate()->format('Y-m-d')][$cover->getSlotIndex()][] = $cover;
        }

        return array_map(function (\DateTimeImmutable $day) use ($dutiesByDay, $coversByDaySlot, $slotTimes, $today, $now): array {
            $key = $day->format('Y-m-d');

            return [
                'date' => $day,
                'isToday' => $key === $today->format('Y-m-d'),
                'slots' => $this->slotsOf($day, $dutiesByDay[$key] ?? [], $coversByDaySlot[$key] ?? [], $slotTimes, $now),
            ];
        }, $days);
    }

    /**
     * One day's boxes: each duty period with its covers, plus a box for each period holding covers but
     * no duty, all in period order.
     *
     * @param \DateTimeImmutable                                                          $day          the day
     * @param list<DutySlot>                                                              $duties       its duty periods
     * @param array<int, non-empty-list<GuardiaCover>>                                    $coversBySlot its covers by period index
     * @param array<int, array{startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}> $slotTimes    period times by slot index
     * @param \DateTimeImmutable                                                          $now          the current instant
     *
     * @return list<GuardiaWeekSlot> the boxes
     */
    private function slotsOf(\DateTimeImmutable $day, array $duties, array $coversBySlot, array $slotTimes, \DateTimeImmutable $now): array
    {
        $dutySlots = array_map(static fn (DutySlot $duty): GuardiaWeekSlot => GuardiaWeekSlot::forDuty($duty, $coversBySlot[$duty->slotIndex] ?? [], $now), $duties);

        $offDutyCovers = array_diff_key($coversBySlot, array_flip(array_map(static fn (DutySlot $duty): int => $duty->slotIndex, $duties)));
        $offDuty = array_map(
            static function (int $slotIndex, array $covers) use ($day, $slotTimes, $now): GuardiaWeekSlot {
                $times = $slotTimes[$slotIndex] ?? null;

                return GuardiaWeekSlot::forCoversOffDuty(
                    $covers,
                    null !== $times ? CalendarDate::at($day, $times['startsAt']) : null,
                    null !== $times ? CalendarDate::at($day, $times['endsAt']) : null,
                    $now,
                );
            },
            array_keys($offDutyCovers),
            array_values($offDutyCovers),
        );

        $slots = [...$dutySlots, ...$offDuty];
        usort($slots, static fn (GuardiaWeekSlot $a, GuardiaWeekSlot $b): int => $a->slotIndex <=> $b->slotIndex);

        return $slots;
    }
}
