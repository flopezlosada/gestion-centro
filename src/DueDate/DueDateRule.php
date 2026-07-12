<?php

declare(strict_types=1);

namespace App\DueDate;

use App\Entity\AcademicYear;
use App\Enum\DueDateRuleKind;

/**
 * A deadline rule carried by a recurring task template. Given a course's term structure, it computes
 * the concrete due date(s) that the yearly task generation will stamp onto the instances it creates.
 *
 * A rule always resolves to a LIST of dates: single-occurrence rules return one, recurring rules
 * (monthly, per term) return several. This uniform shape lets the generator treat every kind the
 * same way. Resolution is pure — a function of the {@see AcademicYear} alone; snapping a date onto a
 * teaching day (avoiding weekends/holidays) is deliberately left to the generator, which owns the
 * {@see \App\Service\SchoolCalendar}.
 *
 * Rules are value objects, persisted on the template as JSON via {@see self::toArray()} and rebuilt
 * with {@see DueDateRuleFactory}.
 */
interface DueDateRule
{
    /**
     * The kind discriminator, used to persist and to rebuild the rule.
     *
     * @return DueDateRuleKind this rule's kind
     */
    public function kind(): DueDateRuleKind;

    /**
     * The concrete due date(s) this rule yields for the given course, in ascending order.
     *
     * @param AcademicYear $year the course whose term structure the dates are computed against
     *
     * @return list<\DateTimeImmutable> one or more due dates (never empty)
     */
    public function resolve(AcademicYear $year): array;

    /**
     * The rule as a plain, JSON-serialisable array including its {@see self::kind()} discriminator.
     *
     * @return array<string, mixed> the persisted shape
     */
    public function toArray(): array;
}
