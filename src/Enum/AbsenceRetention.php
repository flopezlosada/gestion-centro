<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How long the application keeps who was away on which day, in weeks.
 *
 * An enum and not a number of days on purpose. The plazo is promised in the privacy notice, so there is
 * no "never" to choose; and it has to leave the administrative staff time to ask each teacher for their
 * justificante and record it in RAICES, so it cannot be shorter than a week. Four whole weeks are the
 * only plazos that meet both, and a stored value outside them cannot be read back as anything else.
 */
enum AbsenceRetention: int
{
    case ONE_WEEK = 1;
    case TWO_WEEKS = 2;
    case THREE_WEEKS = 3;
    case FOUR_WEEKS = 4;

    /** The plazo until the centre picks one: the longest, so nothing is forgotten before the staff has had its say. */
    public const DEFAULT = self::FOUR_WEEKS;

    /**
     * Human-facing name (Spanish).
     *
     * @return string the plazo, as the settings form offers it
     */
    public function label(): string
    {
        return 1 === $this->value ? '1 semana' : sprintf('%d semanas', $this->value);
    }

    /**
     * The first day whose absences are still kept, counting back from a given day.
     *
     * @param \DateTimeImmutable $today the day the count starts from
     *
     * @return \DateTimeImmutable midnight of the oldest day kept
     */
    public function keptFrom(\DateTimeImmutable $today): \DateTimeImmutable
    {
        return $today->setTime(0, 0)->modify(sprintf('-%d weeks', $this->value));
    }
}
