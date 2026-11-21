<?php

declare(strict_types=1);

namespace App\Booking;

/**
 * One hour of the school week in the grid of a fixed booking: what blocks it and what clashes with it.
 */
final readonly class FixedGridCell
{
    /**
     * @param BookingClash|null  $lesson   the class the timetable has there every week, or null; with one the
     *                                     hour cannot be taken at all
     * @param list<BookingClash> $bookings the days from today to the end of the course somebody already has it,
     *                                     earliest first; they clash only if the series covers those days
     */
    public function __construct(
        public ?BookingClash $lesson,
        public array $bookings,
    ) {
    }
}
