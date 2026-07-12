<?php

declare(strict_types=1);

namespace App\DueDate;

use App\Entity\AcademicYear;
use App\Enum\CalendarAnchor;
use App\Enum\DueDateRuleKind;

/**
 * A calendar anchor shifted by a number of days, e.g. "5 days before the end of term 1"
 * (offset -5 on {@see CalendarAnchor::TERM_1_END}) or "on the first day of the course" (offset 0 on
 * {@see CalendarAnchor::YEAR_START}). A negative offset moves earlier, a positive one later.
 */
final class RelativeToAnchor implements DueDateRule
{
    /**
     * @param CalendarAnchor $anchor     the calendar point to measure from
     * @param int            $offsetDays days to shift; negative = before the anchor, positive = after
     */
    public function __construct(
        private readonly CalendarAnchor $anchor,
        private readonly int $offsetDays,
    ) {
    }

    public function kind(): DueDateRuleKind
    {
        return DueDateRuleKind::RELATIVE_TO_ANCHOR;
    }

    public function resolve(AcademicYear $year): array
    {
        return [$this->anchor->resolve($year)->modify(sprintf('%+d days', $this->offsetDays))];
    }

    public function toArray(): array
    {
        return [
            'kind' => $this->kind()->value,
            'anchor' => $this->anchor->value,
            'offsetDays' => $this->offsetDays,
        ];
    }
}
