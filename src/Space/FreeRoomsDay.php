<?php

declare(strict_types=1);

namespace App\Space;

/**
 * The "aulas libres" sheet for one day: its periods with the count of free spaces on each, and the ONE
 * period being looked at with its rooms already tiered ({@see FreeRoomsSlot}).
 *
 * One period at a time and not the whole day side by side, because the question that brings anybody here
 * is about a concrete hour — the hour of the absence being sorted out — and six columns of the same list
 * made the answer to that question the hardest thing on the screen (the sixth column did not even fit).
 * The other periods stay as a selector with their counts, which is the part of the day-wide view that was
 * actually worth keeping.
 *
 * Built from the marco horario, so a recreo is offered too: everything is free then, which is useless as
 * a suggestion but a fair answer when somebody asks for it. That is why {@see chosen()} never lands on one
 * by itself and why the suggestion in the empty state skips them.
 */
final readonly class FreeRoomsDay
{
    /**
     * @param list<FreeRoomsSlot> $slots   every period of the day, earliest first
     * @param FreeRoomsSlot|null  $current the period being looked at, or null when there is no timetable
     */
    private function __construct(
        public array $slots,
        public ?FreeRoomsSlot $current,
    ) {
    }

    /**
     * Assembles the sheet: the day's frame, what is free at each period, and which period to open.
     *
     * @param list<array{index: int, startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable, isBreak: bool}> $frame        the day's periods, earliest first
     * @param array<int, RoomAvailability>                                                                    $availability period index → what is free then
     * @param int|null                                                                                        $wanted       the period asked for in the URL, or null for the default
     * @param \DateTimeImmutable|null                                                                         $now          the current time when the sheet is on today, null on any other day
     *
     * @return self the sheet
     */
    public static function of(array $frame, array $availability, ?int $wanted, ?\DateTimeImmutable $now): self
    {
        $slots = array_map(
            static fn (array $s): FreeRoomsSlot => new FreeRoomsSlot(
                $s['index'],
                $s['startsAt'],
                $s['endsAt'],
                $s['isBreak'],
                ($availability[$s['index']] ?? null)?->largestFirst() ?? [],
            ),
            $frame,
        );

        return new self($slots, self::chosen($slots, $wanted, $now));
    }

    /**
     * The next period worth suggesting when the one open has nothing free: the first teaching period AFTER
     * it with spaces, and failing that the first one BEFORE it — the sheet is read for a whole morning, so
     * "prueba a las 08:25" is a real answer at last period and a dead end is not.
     *
     * Recreos are skipped even though everything is free then: sending somebody to the recreo when they
     * asked where to put a class during third period is not an alternative, it is a non-answer.
     *
     * @return FreeRoomsSlot|null the period to offer, or null when the whole day is full
     */
    public function nextWithRooms(): ?FreeRoomsSlot
    {
        if (null === $this->current) {
            return null;
        }

        $usable = array_values(array_filter($this->slots, fn (FreeRoomsSlot $s): bool => !$s->isBreak && $s->hasRooms() && $s->index !== $this->current->index));
        $after = array_values(array_filter($usable, fn (FreeRoomsSlot $s): bool => $s->startsAt > $this->current->startsAt));

        return $after[0] ?? ($usable[0] ?? null);
    }

    /**
     * Which period the sheet opens on: the one asked for in the URL, or else the hour the centre is living
     * — the first TEACHING period that has not finished yet, falling back to the first of the day.
     *
     * One rule covers the four cases that matter, which is why it is written as one: mid-lesson it picks
     * that lesson, during a recreo it picks the lesson about to start (the recreo itself is a non-answer,
     * see the class note), before school it picks first period, and after school it starts again at the
     * top rather than leaving the screen on a period nobody can act on.
     *
     * @param list<FreeRoomsSlot>     $slots  the day's periods, earliest first
     * @param int|null                $wanted the period asked for, or null for the default
     * @param \DateTimeImmutable|null $now    the current time on today's sheet, null on any other day
     *
     * @return FreeRoomsSlot|null the period to open, or null when the day has none
     */
    private static function chosen(array $slots, ?int $wanted, ?\DateTimeImmutable $now): ?FreeRoomsSlot
    {
        if (null !== $wanted) {
            foreach ($slots as $slot) {
                if ($slot->index === $wanted) {
                    return $slot;
                }
            }
        }

        $teaching = array_values(array_filter($slots, static fn (FreeRoomsSlot $s): bool => !$s->isBreak));
        if (null !== $now) {
            // Compared on the time of day alone: the period's times come from the marco horario, which is
            // the shape of ANY school day and carries no date of its own.
            $time = $now->format('H:i:s');
            foreach ($teaching as $slot) {
                if ($slot->endsAt->format('H:i:s') > $time) {
                    return $slot;
                }
            }
        }

        return $teaching[0] ?? ($slots[0] ?? null);
    }
}
