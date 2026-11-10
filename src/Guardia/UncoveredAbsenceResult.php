<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Entity\User;

/**
 * Outcome of marking several teachers away without cover ({@see AbsenceRegistrar::registerWithoutCover()}),
 * so the screen can say exactly who was marked and who was not, and why.
 */
final class UncoveredAbsenceResult
{
    /**
     * @param list<User> $marked      the teachers now away without cover
     * @param list<User> $alreadyAway skipped: every one of those periods was already an ordinary absence
     * @param list<User> $notBooked   skipped: the timetable books them for none of those periods
     */
    public function __construct(
        public readonly array $marked,
        public readonly array $alreadyAway,
        public readonly array $notBooked,
    ) {
    }
}
