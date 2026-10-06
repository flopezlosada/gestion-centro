<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Agenda\DutySlot;
use App\Entity\GuardiaCover;

/**
 * One box of a teacher's guardia week: a period the timetable puts them on duty, with whoever they
 * have to cover in it — or a cover that falls OUTSIDE those periods (a doblete, a one-off support), which
 * still has to be on the week or it would vanish from the teacher's view.
 *
 * Invariant kept by the two named constructors: an off-duty box always carries at least one cover.
 */
final readonly class GuardiaWeekSlot
{
    /**
     * @param list<GuardiaCover> $covers the covers in this period, possibly none for a duty period
     */
    private function __construct(
        public int $slotIndex,
        public ?string $label,
        public ?\DateTimeImmutable $startsAt,
        public ?\DateTimeImmutable $endsAt,
        public array $covers,
        public bool $past,
        public bool $duringBreak = false,
    ) {
    }

    /**
     * A period of the teacher's own timetable, with the covers that fell in it.
     *
     * @param DutySlot           $duty   the standing duty period
     * @param list<GuardiaCover> $covers the covers assigned to them in that period
     * @param \DateTimeImmutable $now    the current instant
     *
     * @return self the box
     */
    public static function forDuty(DutySlot $duty, array $covers, \DateTimeImmutable $now): self
    {
        return new self($duty->slotIndex, $duty->label(), $duty->startsAt, $duty->endsAt, $covers, $duty->endsAt < $now, $duty->duringBreak);
    }

    /**
     * Covers assigned in a period that is NOT one of the teacher's duty periods. Without known times the
     * period can only be called past once its whole day is.
     *
     * @param non-empty-list<GuardiaCover> $covers   the covers, all in the same period of the same day
     * @param ?\DateTimeImmutable          $startsAt the period start on that day, null if the timetable lacks it
     * @param ?\DateTimeImmutable          $endsAt   the period end on that day, null if the timetable lacks it
     * @param \DateTimeImmutable           $now      the current instant
     *
     * @return self the box
     */
    public static function forCoversOffDuty(array $covers, ?\DateTimeImmutable $startsAt, ?\DateTimeImmutable $endsAt, \DateTimeImmutable $now): self
    {
        $past = null !== $endsAt ? $endsAt < $now : $covers[0]->getDate()->modify('+1 day') <= $now;

        return new self($covers[0]->getSlotIndex(), null, $startsAt, $endsAt, $covers, $past);
    }

    /**
     * Whether somebody has to be covered in this period.
     *
     * @return bool true with at least one cover
     */
    public function isCovering(): bool
    {
        return [] !== $this->covers;
    }

    /**
     * Whether the period is not one of the teacher's own duty periods.
     *
     * @return bool true for a cover outside their guardia hours
     */
    public function isOffDuty(): bool
    {
        return null === $this->label;
    }
}
