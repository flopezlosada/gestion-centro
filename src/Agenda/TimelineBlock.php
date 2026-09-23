<?php

declare(strict_types=1);

namespace App\Agenda;

/**
 * One timed thing on the day/week timeline grid ({@see DayTimeline}): a class, a guardia or a meeting,
 * drawn as a block sized to its real duration instead of a line of text. Personal events are not blocks
 * on purpose — their one-tap "done" toggle is a form, which cannot nest inside a block's link — and
 * neither is anything without a clock time (a task); both stay in the day's plain list.
 */
final readonly class TimelineBlock
{
    public function __construct(
        public string $kind,
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $endsAt,
        public string $title,
        public ?string $subtitle,
        public string $href,
        public string $colorClass,
        public bool $flagged = false,
    ) {
    }

    /**
     * Whether this block's time span shares any minute with another's — the test the grid's column
     * layout groups on.
     */
    public function overlaps(self $other): bool
    {
        return $this->startsAt < $other->endsAt && $other->startsAt < $this->endsAt;
    }
}
