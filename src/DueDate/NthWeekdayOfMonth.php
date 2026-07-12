<?php

declare(strict_types=1);

namespace App\DueDate;

use App\Entity\AcademicYear;
use App\Enum\DueDateRuleKind;
use App\Enum\Weekday;
use App\Enum\WeekOrdinal;

/**
 * The Nth occurrence of a weekday within a month, e.g. "first Monday of October" or "last Friday of
 * June". The calendar year is decided by the course the same way {@see FixedDate} does.
 */
final class NthWeekdayOfMonth implements DueDateRule
{
    /**
     * @param WeekOrdinal $ordinal which occurrence (first…fourth, or last)
     * @param Weekday     $weekday the weekday
     * @param int         $month   the month, 1 to 12
     */
    public function __construct(
        private readonly WeekOrdinal $ordinal,
        private readonly Weekday $weekday,
        private readonly int $month,
    ) {
        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException(sprintf('Mes inválido: %d (debe ser 1-12).', $month));
        }
    }

    public function kind(): DueDateRuleKind
    {
        return DueDateRuleKind::NTH_WEEKDAY;
    }

    public function resolve(AcademicYear $year): array
    {
        $calendarYear = $year->calendarYearForMonth($this->month);
        $firstOfMonth = new \DateTimeImmutable(sprintf('%04d-%02d-01', $calendarYear, $this->month));
        $daysInMonth = (int) $firstOfMonth->format('t');

        // Day-of-month of the first occurrence of the wanted weekday.
        $shift = ($this->weekday->value - (int) $firstOfMonth->format('N') + 7) % 7;
        $firstOccurrence = 1 + $shift;

        $weekOffset = $this->ordinal->weekOffset();
        if (null === $weekOffset) {
            // "Last": step forward in whole weeks as far as the month allows.
            $day = $firstOccurrence + intdiv($daysInMonth - $firstOccurrence, 7) * 7;
        } else {
            $day = $firstOccurrence + $weekOffset * 7;
            // Guard: if that occurrence does not exist this month, fall back to the last one.
            if ($day > $daysInMonth) {
                $day = $firstOccurrence + intdiv($daysInMonth - $firstOccurrence, 7) * 7;
            }
        }

        return [$firstOfMonth->setDate($calendarYear, $this->month, $day)];
    }

    public function toArray(): array
    {
        return [
            'kind' => $this->kind()->value,
            'ordinal' => $this->ordinal->value,
            'weekday' => $this->weekday->value,
            'month' => $this->month,
        ];
    }
}
