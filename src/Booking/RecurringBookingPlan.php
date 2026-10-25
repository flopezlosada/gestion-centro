<?php

declare(strict_types=1);

namespace App\Booking;

use App\Entity\Booking;

/**
 * What {@see RecurringBookingScheduler} works out for a fixed booking: the bookings it would make, or why it
 * cannot. Either one or the other is what the caller acts on — there are bookings to persist only when
 * there are no clashes, because a fixed booking is made whole or not at all.
 */
final readonly class RecurringBookingPlan
{
    /**
     * @param list<Booking>      $bookings one per teaching day and period, not persisted
     * @param list<BookingClash> $clashes  what stands in the way, earliest first
     */
    public function __construct(
        public array $bookings,
        public array $clashes,
    ) {
    }

    /**
     * Whether the fixed booking can be made as asked.
     *
     * @return bool true when nothing stands in the way and it falls on at least one teaching day
     */
    public function canBeMade(): bool
    {
        return [] === $this->clashes && [] !== $this->bookings;
    }
}
