<?php

declare(strict_types=1);

namespace App\Enum;

use App\Entity\AcademicYear;

/**
 * A named point of the school calendar a deadline can be anchored to: the start or end of any term,
 * or the start or end of the whole course. Resolving an anchor against an {@see AcademicYear} yields
 * a concrete date, which a {@see \App\DueDate\RelativeToAnchor} rule then shifts by an offset.
 */
enum CalendarAnchor: string
{
    case TERM_1_START = 'term_1_start';
    case TERM_1_END = 'term_1_end';
    case TERM_2_START = 'term_2_start';
    case TERM_2_END = 'term_2_end';
    case TERM_3_START = 'term_3_start';
    case TERM_3_END = 'term_3_end';
    case YEAR_START = 'year_start';
    case YEAR_END = 'year_end';

    /**
     * The concrete date this anchor points to within the given course.
     *
     * @param AcademicYear $year the course whose term structure resolves the anchor
     *
     * @return \DateTimeImmutable the anchored date
     */
    public function resolve(AcademicYear $year): \DateTimeImmutable
    {
        return match ($this) {
            self::TERM_1_START => $year->getTermStart(1),
            self::TERM_1_END => $year->getTermEnd(1),
            self::TERM_2_START => $year->getTermStart(2),
            self::TERM_2_END => $year->getTermEnd(2),
            self::TERM_3_START => $year->getTermStart(3),
            self::TERM_3_END => $year->getTermEnd(3),
            self::YEAR_START => $year->getYearStart(),
            self::YEAR_END => $year->getYearEnd(),
        };
    }

    /**
     * Human-facing label (Spanish).
     *
     * @return string the anchor label
     */
    public function label(): string
    {
        return match ($this) {
            self::TERM_1_START => 'Inicio del 1.er trimestre',
            self::TERM_1_END => 'Fin del 1.er trimestre',
            self::TERM_2_START => 'Inicio del 2.º trimestre',
            self::TERM_2_END => 'Fin del 2.º trimestre',
            self::TERM_3_START => 'Inicio del 3.er trimestre',
            self::TERM_3_END => 'Fin del 3.er trimestre',
            self::YEAR_START => 'Inicio del curso',
            self::YEAR_END => 'Fin del curso',
        };
    }
}
