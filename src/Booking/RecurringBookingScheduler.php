<?php

declare(strict_types=1);

namespace App\Booking;

use App\Entity\AcademicYear;
use App\Entity\Booking;
use App\Entity\RecurringBooking;
use App\Entity\User;
use App\Enum\Weekday;
use App\Repository\BookingRepository;
use App\Repository\ScheduleEntryRepository;
use App\Service\SchoolCalendar;

/**
 * Turns a fixed booking into its days: one {@see Booking} per teaching day and period between its dates,
 * skipping weekends, breaks and the registered non-teaching days.
 *
 * And says what stands in the way, because a fixed booking is made whole or not at all — a series with
 * holes the holder does not know about is worse than none:
 * - a day already booked by somebody (one-off or another fixed booking), with who has it;
 * - for a ROOM, a class the timetable puts there that weekday and period. Read on the ordinary weekly
 *   timetable, which is what "una clase fija" collides with; a room change a space plan approves for one
 *   day is not looked at (it did not exist when the series was asked for, and it is the plan that moves).
 *
 * Nothing is persisted here: the caller persists the bookings in one flush, so the UNIQUE index still has
 * the last word if somebody books one of those days in the meantime.
 */
final readonly class RecurringBookingScheduler
{
    public function __construct(
        private SchoolCalendar $calendar,
        private BookingRepository $bookings,
        private ScheduleEntryRepository $schedule,
    ) {
    }

    /**
     * Works out the days of a fixed booking and what clashes with them.
     *
     * @param RecurringBooking  $series    the fixed booking, not yet persisted
     * @param User              $createdBy who is making it
     * @param AcademicYear|null $year      the course its dates fall in (supplies the timetable), or null
     *
     * @return RecurringBookingPlan the bookings to make, or the clashes
     */
    public function plan(RecurringBooking $series, User $createdBy, ?AcademicYear $year): RecurringBookingPlan
    {
        $wanted = [];
        foreach ($this->calendar->lectiveDaysBetween($series->getStartsOn(), $series->getEndsOn()) as $day) {
            foreach ($series->slotsOn(Weekday::from((int) $day->format('N'))) as $slot) {
                $wanted[$day->format('Y-m-d').'#'.$slot] = [$day, $slot];
            }
        }

        $resource = $series->getRoom() ?? $series->getMaterial() ?? throw new \LogicException('A series holds a room or a material.');
        $weekdays = array_values(array_unique(array_map(static fn (array $w): int => (int) $w[0]->format('N'), $wanted)));
        $clashes = [...$this->timetableClashes($series, $year, $weekdays)];
        foreach ($this->bookings->findForResourceBetween($resource, $series->getStartsOn(), $series->getEndsOn()) as $taken) {
            if (isset($wanted[$taken->getDate()->format('Y-m-d').'#'.$taken->getSlotIndex()])) {
                $clashes[] = new BookingClash(
                    $taken->getDate(),
                    Weekday::from((int) $taken->getDate()->format('N')),
                    $taken->getSlotIndex(),
                    null !== $taken->getSeries()
                        ? sprintf('Reserva fija de %s', $taken->holderName())
                        : sprintf('Reservado por %s: %s', $taken->holderName(), $taken->getPurpose()),
                );
            }
        }

        return new RecurringBookingPlan(
            array_values(array_map(static fn (array $w): Booking => Booking::forSeries($series, $createdBy, $w[0], $w[1]), $wanted)),
            $clashes,
        );
    }

    /**
     * The classes the timetable puts in the series' room at the periods it asks for, once per weekday and
     * period (it is every week). None for material, nor without a course to read the timetable from.
     *
     * Only the weekdays the dates actually reach: a series of one Tuesday with Monday in its pattern never
     * touches a Monday, and its Monday class is no reason to refuse it.
     *
     * @param RecurringBooking  $series   the fixed booking
     * @param AcademicYear|null $year     the course
     * @param list<int>         $weekdays the ISO weekdays its teaching days fall on
     *
     * @return list<BookingClash> the clashes, by weekday and period
     */
    private function timetableClashes(RecurringBooking $series, ?AcademicYear $year, array $weekdays): array
    {
        $room = $series->getRoom();
        if (null === $room || null === $year) {
            return [];
        }

        $clashes = [];
        foreach ($series->getPattern() as $weekdayValue => $slots) {
            if (!\in_array($weekdayValue, $weekdays, true)) {
                continue;
            }
            $weekday = Weekday::from($weekdayValue);
            $bySlot = $this->schedule->occupancyBySlotOn($year, $weekday);
            foreach ($slots as $slot) {
                foreach ($bySlot[$slot] ?? [] as $entry) {
                    if ($entry->getRoom() === $room) {
                        $clashes[] = new BookingClash(null, $weekday, $slot, sprintf(
                            'Clase de %s con %s en el horario',
                            $entry->getGroupName() ?? $entry->getSubjectName() ?? 'un grupo',
                            $entry->getTeacher()->getFullName(),
                        ));
                    }
                }
            }
        }

        return $clashes;
    }
}
