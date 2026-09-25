<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\Department;
use App\Repository\ScheduleEntryRepository;

/**
 * Which department a subject belongs to, read from the timetable: the one whose teachers give the most
 * of it. Peñalara says which department each TEACHER is in, never which department a subject is — and
 * "any department that teaches it" would hand Biología to Matemáticas because a maths teacher gives a
 * few of its hours. The most sessions is the department that owns it; a tie (Atención Educativa, spread
 * thin across the school) gives it to every tied department.
 *
 * Per subject and not per (subject, level): measured on the centre's timetable, owning by level changed
 * two subjects only, both for the worse — one group of «Refuerzo de Matemáticas» taught by a biology
 * teacher would have handed that level's list to Biología.
 *
 * It decides whose topic list a head of department manages ({@see \App\Controller\AdminTopicController}).
 */
final class SubjectOwnership
{
    /** @var array<int, array<string, list<int>>> course id → subject → owning department ids */
    private array $cache = [];

    public function __construct(private readonly ScheduleEntryRepository $schedule)
    {
    }

    /**
     * The departments each subject of a course belongs to.
     *
     * @param AcademicYear $year the course
     *
     * @return array<string, list<int>> subject → the ids of the departments that give most of it
     */
    public function departmentsBySubject(AcademicYear $year): array
    {
        return $this->cache[(int) $year->getId()] ??= $this->compute($year);
    }

    /**
     * Whether a subject belongs to a department in a course.
     *
     * @param string       $subject    the subject
     * @param Department   $department the department
     * @param AcademicYear $year       the course
     */
    public function belongsTo(string $subject, Department $department, AcademicYear $year): bool
    {
        return \in_array((int) $department->getId(), $this->departmentsBySubject($year)[$subject] ?? [], true);
    }

    /**
     * @return array<string, list<int>> subject → owning department ids
     */
    private function compute(AcademicYear $year): array
    {
        $bySubject = [];
        foreach ($this->schedule->sessionsBySubjectAndDepartment($year) as $row) {
            $bySubject[$row['subject']][$row['department']] = $row['sessions'];
        }

        return array_map(static function (array $sessions): array {
            $most = max($sessions);

            return array_keys(array_filter($sessions, static fn (int $n): bool => $n === $most));
        }, $bySubject);
    }
}
