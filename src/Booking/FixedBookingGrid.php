<?php

declare(strict_types=1);

namespace App\Booking;

use App\Entity\AcademicYear;
use App\Entity\Material;
use App\Entity\Room;
use App\Enum\Weekday;
use App\Repository\BookingRepository;
use App\Repository\ScheduleEntryRepository;
use App\Service\SchoolCalendar;

/**
 * What stands in the way of a fixed booking, hour by hour of the school week, for ONE room or piece of
 * material: the grid the person paints their weekly hours on.
 *
 * Two kinds of obstacle, and they are not the same:
 * - a class of the weekly timetable in the room blocks the hour ({@see FixedGridCell::$lesson}): it is every
 *   week, so the screen does not offer it. The scheduler is a little more lenient (it only looks at the
 *   weekdays the dates reach), which is why a series of a single Tuesday could still be made by hand;
 * - a booking somebody already has on a teaching day from today to the end of the course (a fixed one or a
 *   one-off) is a clash that depends on the dates the series will cover ({@see FixedGridCell::$bookings}).
 *   The cell stays free to tick, and the screen says which days clash for the dates asked. Blocking it
 *   outright would forbid a series that starts after the other one ends.
 *
 * {@see RecurringBookingScheduler} keeps the last word when the series is made: this only lets the person
 * see it before sending, with the same days (the teaching ones) and the same texts.
 */
final readonly class FixedBookingGrid
{
    public function __construct(
        private BookingRepository $bookings,
        private ScheduleEntryRepository $schedule,
        private SchoolCalendar $calendar,
    ) {
    }

    /**
     * The grid of a resource.
     *
     * @param Room|Material      $resource  the room or material the series would take
     * @param AcademicYear|null  $year      the course, which supplies the timetable and the last day; null
     *                                      means no timetable to look at
     * @param \DateTimeImmutable $today     the first day that counts
     * @param list<int>          $slotIndex the periods of the day
     *
     * @return array<int, array<int, FixedGridCell>> ISO weekday → period index → what is there
     */
    public function forResource(Room|Material $resource, ?AcademicYear $year, \DateTimeImmutable $today, array $slotIndex): array
    {
        $until = $year?->getYearEnd() ?? $today->modify('+1 year');

        // Solo los días lectivos, como el planificador: una reserva en un día que se ha declarado festivo después
        // no choca con nada, y avisar de ella dejaría al usuario sin poder crear lo que el servidor aceptaría.
        $teaching = array_fill_keys(array_map(static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d'), $this->calendar->lectiveDaysBetween($today, $until)), true);
        $taken = [];
        foreach ($this->bookings->findForResourceBetween($resource, $today, $until) as $booking) {
            if (!isset($teaching[$booking->getDate()->format('Y-m-d')])) {
                continue;
            }
            $clash = BookingClash::forBooking($booking);
            $taken[$clash->weekday->value][$clash->slotIndex][] = $clash;
        }

        $grid = [];
        foreach (Weekday::schoolWeek() as $weekday) {
            $lessons = $this->lessonsOf($resource, $year, $weekday);
            foreach ($slotIndex as $slot) {
                $grid[$weekday->value][$slot] = new FixedGridCell($lessons[$slot] ?? null, $taken[$weekday->value][$slot] ?? []);
            }
        }

        return $grid;
    }

    /**
     * The class the timetable has in a room each period of a weekday. None for material, nor without a course.
     *
     * @param Room|Material     $resource the resource
     * @param AcademicYear|null $year     the course
     * @param Weekday           $weekday  the weekday
     *
     * @return array<int, BookingClash> period index → the class
     */
    private function lessonsOf(Room|Material $resource, ?AcademicYear $year, Weekday $weekday): array
    {
        if (!$resource instanceof Room || null === $year) {
            return [];
        }

        $lessons = [];
        foreach ($this->schedule->occupancyBySlotOn($year, $weekday) as $slot => $entries) {
            foreach ($entries as $entry) {
                if ($entry->getRoom() === $resource) {
                    $lessons[$slot] = BookingClash::forLesson($weekday, $slot, $entry);
                }
            }
        }

        return $lessons;
    }
}
