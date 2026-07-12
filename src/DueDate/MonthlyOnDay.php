<?php

declare(strict_types=1);

namespace App\DueDate;

use App\Entity\AcademicYear;
use App\Enum\DueDateRuleKind;

/**
 * A given day of every month within the course, e.g. "the 5th of each month". Yields one date per
 * month between the start and end of the year (inclusive), each clamped to the month's length and
 * kept within the course bounds — so a "5th" in a month whose teaching only starts on the 15th, or
 * after the last teaching day, is dropped.
 */
final class MonthlyOnDay implements DueDateRule
{
    /**
     * @param int $day the day of the month, 1 to 31 (clamped to each month's length)
     */
    public function __construct(private readonly int $day)
    {
        if ($day < 1 || $day > 31) {
            throw new \InvalidArgumentException(sprintf('Día inválido: %d (debe ser 1-31).', $day));
        }
    }

    public function kind(): DueDateRuleKind
    {
        return DueDateRuleKind::MONTHLY;
    }

    public function resolve(AcademicYear $year): array
    {
        $start = $year->getYearStart();
        $end = $year->getYearEnd();

        $dates = [];
        $cursor = new \DateTimeImmutable($start->format('Y-m-01'));
        $lastMonth = new \DateTimeImmutable($end->format('Y-m-01'));

        while ($cursor <= $lastMonth) {
            $day = min($this->day, (int) $cursor->format('t'));
            $candidate = $cursor->setDate((int) $cursor->format('Y'), (int) $cursor->format('n'), $day);
            // Keep only the occurrences that fall within the course (a monthly day before the first
            // teaching day or after the last is not a real deadline).
            if ($candidate >= $start && $candidate <= $end) {
                $dates[] = $candidate;
            }
            $cursor = $cursor->modify('first day of next month');
        }

        return $dates;
    }

    public function toArray(): array
    {
        return ['kind' => $this->kind()->value, 'day' => $this->day];
    }
}
