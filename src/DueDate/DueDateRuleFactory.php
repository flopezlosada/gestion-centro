<?php

declare(strict_types=1);

namespace App\DueDate;

use App\Enum\CalendarAnchor;
use App\Enum\DueDateRuleKind;
use App\Enum\TermBoundary;
use App\Enum\Weekday;
use App\Enum\WeekOrdinal;

/**
 * Rebuilds a {@see DueDateRule} value object from its persisted array shape (see
 * {@see DueDateRule::toArray()}). The inverse of serialisation: the entity stores JSON, this turns
 * it back into the right rule class.
 */
final class DueDateRuleFactory
{
    /**
     * @param array<string, mixed> $data the persisted rule, including its "kind" discriminator
     *
     * @return DueDateRule the reconstructed rule
     *
     * @throws \InvalidArgumentException if the kind is missing/unknown or a parameter is absent
     */
    public static function fromArray(array $data): DueDateRule
    {
        $kind = DueDateRuleKind::tryFrom(self::string($data, 'kind'))
            ?? throw new \InvalidArgumentException(sprintf('Tipo de regla de fecha desconocido: "%s".', self::string($data, 'kind')));

        return match ($kind) {
            DueDateRuleKind::FIXED => new FixedDate(self::int($data, 'month'), self::int($data, 'day')),
            DueDateRuleKind::NTH_WEEKDAY => new NthWeekdayOfMonth(
                WeekOrdinal::from(self::string($data, 'ordinal')),
                Weekday::from(self::int($data, 'weekday')),
                self::int($data, 'month'),
            ),
            DueDateRuleKind::RELATIVE_TO_ANCHOR => new RelativeToAnchor(
                CalendarAnchor::from(self::string($data, 'anchor')),
                self::int($data, 'offsetDays'),
            ),
            DueDateRuleKind::MONTHLY => new MonthlyOnDay(self::int($data, 'day')),
            DueDateRuleKind::PER_TERM => new PerTerm(TermBoundary::from(self::string($data, 'boundary'))),
        };
    }

    /**
     * @param array<string, mixed> $data the source array
     * @param string               $key  the required key
     *
     * @return int the value coerced to int
     */
    private static function int(array $data, string $key): int
    {
        if (!isset($data[$key]) || !is_numeric($data[$key])) {
            throw new \InvalidArgumentException(sprintf('Falta el parámetro entero "%s" en la regla de fecha.', $key));
        }

        return (int) $data[$key];
    }

    /**
     * @param array<string, mixed> $data the source array
     * @param string               $key  the required key
     *
     * @return string the value coerced to string
     */
    private static function string(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_scalar($data[$key])) {
            throw new \InvalidArgumentException(sprintf('Falta el parámetro "%s" en la regla de fecha.', $key));
        }

        return (string) $data[$key];
    }
}
