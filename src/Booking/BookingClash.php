<?php

declare(strict_types=1);

namespace App\Booking;

use App\Enum\Weekday;

/**
 * One reason a fixed booking cannot be made as asked: a day already booked by somebody, or — for a room —
 * a class the timetable puts there every week.
 */
final readonly class BookingClash
{
    /**
     * @param \DateTimeImmutable|null $date      the day of the clash, or null when it is every week (a class)
     * @param Weekday                 $weekday   the weekday
     * @param int                     $slotIndex the period
     * @param string                  $what      what is there already, ready to show
     */
    public function __construct(
        public ?\DateTimeImmutable $date,
        public Weekday $weekday,
        public int $slotIndex,
        public string $what,
    ) {
    }
}
