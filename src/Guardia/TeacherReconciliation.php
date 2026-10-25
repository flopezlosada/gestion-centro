<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Entity\User;

/**
 * How {@see TimetableImporter} resolved the export's teachers to people, before anything is written.
 *
 * Peñalara reuses a teacher code from one course to the next for whoever now holds that post — a
 * substitute inherits the code of the person on leave — so the code alone cannot say who someone is.
 * That is why a resolution has four parts and not two: besides who matched and who did not, it carries
 * the codes that change hands ({@see $codesToRecord}) and the people who lose their code to someone else
 * ({@see $displaced}), whose imported timetable no longer belongs to them.
 *
 * Immutable value object; nothing here has touched the database yet, which is what lets a dry run
 * report exactly what a real run would do.
 */
final class TeacherReconciliation
{
    /**
     * @param array<string, User>   $matched       Peñalara code → the person who gets that code's timetable
     * @param array<string, string> $unmatched     Peñalara code → name of the teachers matched to nobody
     * @param array<string, User>   $codesToRecord Peñalara code → the person who must hold it from now on
     *                                             (matched by name, so the code is new to them)
     * @param array<string, User>   $displaced     Peñalara code → the person who held it until now and is
     *                                             not the one the export names under it
     */
    public function __construct(
        public readonly array $matched,
        public readonly array $unmatched,
        public readonly array $codesToRecord,
        public readonly array $displaced,
    ) {
    }
}
