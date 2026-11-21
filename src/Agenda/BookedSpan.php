<?php

declare(strict_types=1);

namespace App\Agenda;

use App\Entity\Booking;

/**
 * What the viewer has booked of ONE room or piece of material on ONE day, as a single line: the hours they
 * took (two periods in a row are one booking in the head of the person, not two) with the clock times when
 * the course has a timetable frame.
 */
final readonly class BookedSpan
{
    /**
     * @param \DateTimeImmutable      $day         the day, at midnight
     * @param string                  $resourceName what is booked
     * @param list<int>               $slotIndexes  the periods taken, ascending
     * @param \DateTimeImmutable|null $startsAt     when the first period starts, or null without a frame
     * @param \DateTimeImmutable|null $endsAt       when the last period ends, or null without a frame
     * @param string                  $purpose      what it is for
     * @param string|null             $groupName    the group it is for, if any
     */
    public function __construct(
        public \DateTimeImmutable $day,
        public string $resourceName,
        public array $slotIndexes,
        public ?\DateTimeImmutable $startsAt,
        public ?\DateTimeImmutable $endsAt,
        public string $purpose,
        public ?string $groupName,
    ) {
    }

    /**
     * Groups one-off bookings into lines: the same thing, the same day and the same reason are one line with
     * all its hours. Another reason on the same thing and day is another line.
     *
     * @param list<Booking>                                                                          $bookings the viewer's bookings, any order
     * @param array<int, array{startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}> $frame    the course's periods by index, or empty
     *
     * @return list<self> the lines, earliest first
     */
    public static function group(array $bookings, array $frame): array
    {
        $groups = [];
        foreach ($bookings as $booking) {
            $key = implode('|', [$booking->getDate()->format('Y-m-d'), $booking->getRoom()?->getId() ?? 'm'.$booking->getMaterial()?->getId(), $booking->getPurpose(), $booking->getGroupName() ?? '']);
            $groups[$key][] = $booking;
        }

        $spans = array_map(static function (array $members) use ($frame): self {
            $slots = array_map(static fn (Booking $b): int => $b->getSlotIndex(), $members);
            sort($slots);
            $day = $members[0]->getDate()->setTime(0, 0);
            $first = $frame[$slots[0]]['startsAt'] ?? null;
            $last = $frame[$slots[\count($slots) - 1]]['endsAt'] ?? null;

            return new self(
                $day,
                $members[0]->resourceName(),
                $slots,
                null !== $first ? $day->setTime((int) $first->format('H'), (int) $first->format('i')) : null,
                null !== $last ? $day->setTime((int) $last->format('H'), (int) $last->format('i')) : null,
                $members[0]->getPurpose(),
                $members[0]->getGroupName(),
            );
        }, array_values($groups));
        usort($spans, static fn (self $a, self $b): int => [$a->day, $a->slotIndexes[0]] <=> [$b->day, $b->slotIndexes[0]]);

        return $spans;
    }
}
