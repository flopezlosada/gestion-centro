<?php

declare(strict_types=1);

namespace App\DueDate;

use App\Entity\AcademicYear;
use App\Enum\DueDateRuleKind;

/**
 * A fixed calendar day within the course, e.g. "30 September" or "1 June". The calendar year is
 * decided by the course: months from September on fall in the first year, January onward in the
 * second. A day that overflows the month (e.g. 31 in a 30-day month) is clamped to the last day.
 */
final class FixedDate implements DueDateRule
{
    /**
     * @param int $month the month, 1 (January) to 12 (December)
     * @param int $day   the day of the month, 1 to 31 (clamped to the month's length on resolution)
     */
    public function __construct(private readonly int $month, private readonly int $day)
    {
        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException(sprintf('Mes inválido: %d (debe ser 1-12).', $month));
        }
        if ($day < 1 || $day > 31) {
            throw new \InvalidArgumentException(sprintf('Día inválido: %d (debe ser 1-31).', $day));
        }
    }

    public function kind(): DueDateRuleKind
    {
        return DueDateRuleKind::FIXED;
    }

    public function resolve(AcademicYear $year): array
    {
        $calendarYear = $year->calendarYearForMonth($this->month);
        $firstOfMonth = new \DateTimeImmutable(sprintf('%04d-%02d-01', $calendarYear, $this->month));
        $day = min($this->day, (int) $firstOfMonth->format('t'));

        return [$firstOfMonth->setDate($calendarYear, $this->month, $day)];
    }

    public function toArray(): array
    {
        return ['kind' => $this->kind()->value, 'month' => $this->month, 'day' => $this->day];
    }
}
