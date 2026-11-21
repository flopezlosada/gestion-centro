<?php

declare(strict_types=1);

namespace App\Booking;

use App\Entity\Booking;
use App\Entity\ScheduleEntry;
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

    /**
     * The clash a day already booked makes: a fixed booking of somebody, or a one-off with who has it and why.
     *
     * @param Booking $taken the booking that is there
     *
     * @return self the clash, on that booking's day and period
     */
    public static function forBooking(Booking $taken): self
    {
        return new self(
            $taken->getDate(),
            Weekday::from((int) $taken->getDate()->format('N')),
            $taken->getSlotIndex(),
            null !== $taken->getSeries()
                ? \sprintf('Reserva fija de %s', $taken->holderName())
                : \sprintf('Reservado por %s: %s', $taken->holderName(), $taken->getPurpose()),
        );
    }

    /**
     * The clash a class of the weekly timetable makes: it is there every week, so it has no date.
     *
     * @param Weekday       $weekday   the weekday of the class
     * @param int           $slotIndex the period of the class
     * @param ScheduleEntry $entry     the lesson
     *
     * @return self the clash, without a date
     */
    public static function forLesson(Weekday $weekday, int $slotIndex, ScheduleEntry $entry): self
    {
        return new self(null, $weekday, $slotIndex, \sprintf(
            'Clase de %s con %s en el horario',
            $entry->getGroupName() ?? $entry->getSubjectName() ?? 'un grupo',
            $entry->getTeacher()->getFullName(),
        ));
    }
}
