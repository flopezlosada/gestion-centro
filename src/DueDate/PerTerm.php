<?php

declare(strict_types=1);

namespace App\DueDate;

use App\Entity\AcademicYear;
use App\Enum\DueDateRuleKind;
use App\Enum\TermBoundary;

/**
 * The start or the end of every term, e.g. "the end of each term" — the classic three-times-a-year
 * deadline. Yields three dates, one per term, in order.
 */
final class PerTerm implements DueDateRule
{
    /**
     * @param TermBoundary $boundary whether to take each term's start or its end
     */
    public function __construct(private readonly TermBoundary $boundary)
    {
    }

    public function kind(): DueDateRuleKind
    {
        return DueDateRuleKind::PER_TERM;
    }

    public function resolve(AcademicYear $year): array
    {
        $dates = [];
        foreach ([1, 2, 3] as $term) {
            $dates[] = TermBoundary::START === $this->boundary
                ? $year->getTermStart($term)
                : $year->getTermEnd($term);
        }

        return $dates;
    }

    public function toArray(): array
    {
        return ['kind' => $this->kind()->value, 'boundary' => $this->boundary->value];
    }
}
