<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Enum\EducationLevel;
use App\Repository\ScheduleEntryRepository;
use App\Util\GroupCode;

/**
 * The (subject, level) scopes a topic list can exist for — what the head of department's screen offers
 * to manage, before anybody has necessarily planned a single class in it.
 *
 * Read from the current course's timetable rather than from {@see \App\Entity\Topic} itself: a scope with
 * zero topics still needs to be reachable, or a department could never paste its programación before any
 * teacher had used the on-the-fly creation.
 */
final class TopicScopeFinder
{
    public function __construct(private readonly ScheduleEntryRepository $schedule)
    {
    }

    /**
     * Every (subject, level) scope taught in a course, alphabetically by subject then level. The level is
     * read from each group's name ({@see GroupCode::level()}); groups whose name the reader does not
     * recognise fall into the null-level scope of their subject.
     *
     * @param AcademicYear $year the course
     *
     * @return list<array{subject: string, level: EducationLevel|null}> the scopes
     */
    public function scopesFor(AcademicYear $year): array
    {
        $seen = [];
        foreach ($this->schedule->distinctSubjectGroupPairs($year) as $pair) {
            $level = GroupCode::level($pair['grp']);
            $seen[$pair['subject'].'|'.(null !== $level ? $level->value : '')] = ['subject' => $pair['subject'], 'level' => $level];
        }
        ksort($seen);

        return array_values($seen);
    }
}
