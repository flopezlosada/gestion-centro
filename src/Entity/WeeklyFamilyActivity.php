<?php

declare(strict_types=1);

namespace App\Entity;

use App\Agenda\ClassSession;
use App\Enum\Weekday;
use App\Util\SchoolYear;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An activity held in one of the organiser's classes every week ("los martes a 3ª, en 1ºA, grupos
 * interactivos"): it has a session each week that class is given, and only then — not on a holiday, not
 * in a break between terms, not once the course is over.
 *
 * The class is remembered by day, period, groups and subject, as a {@see LessonPlan} remembers it, and
 * NOT by a foreign key to the timetable cell: every Peñalara import deletes and recreates the cells, and
 * would take the activity with them. If the timetable changes and that class is no longer given, the
 * activity simply has no more sessions, and the staff list says so. It belongs to one course: the same
 * groups next year are other pupils and another timetable.
 */
#[ORM\Entity]
class WeeklyFamilyActivity extends FamilyActivity
{
    /** Column widths of the class's groups and subject: what a stored class is cut to and compared at. */
    private const int MAX_GROUPS = 160;
    private const int MAX_SUBJECT = 120;

    /** The course it runs in, as "2026-2027". */
    #[ORM\Column(name: 'school_year', length: 9)]
    private string $schoolYear;

    /** The day of the week of the class. */
    #[ORM\Column(name: 'weekday', type: Types::SMALLINT, enumType: Weekday::class)]
    private Weekday $weekday;

    /** The period of the class (0-based, the Peñalara index), the key {@see ClassSession::$slotIndex} uses. */
    #[ORM\Column(name: 'slot_index', type: Types::SMALLINT)]
    private int $slotIndex;

    /** The class's groups as shown ("B1A, B1B"), as {@see ClassSession::groupNames()} gives them. */
    #[ORM\Column(name: 'group_names', length: self::MAX_GROUPS)]
    private string $groupNames;

    /** The class's subject, as {@see ClassSession::subject()} gives it. */
    #[ORM\Column(name: 'subject', length: self::MAX_SUBJECT)]
    private string $subject;

    /**
     * @param User               $organizer who publishes it, the teacher of the class
     * @param string             $title     what it is
     * @param ClassSession       $class     the class, as given on one day
     * @param \DateTimeImmutable $day       that day: it fixes the day of the week and the course
     */
    public function __construct(User $organizer, string $title, ClassSession $class, \DateTimeImmutable $day)
    {
        parent::__construct($organizer, $title);
        $this->moveTo($class, $day);
    }

    /**
     * Weekly: one session each week its class is given.
     */
    public function isWeekly(): bool
    {
        return true;
    }

    /**
     * Ties the activity to another of the organiser's classes.
     *
     * @param ClassSession       $class the class, as given on one day
     * @param \DateTimeImmutable $day   that day: it fixes the day of the week and the course
     */
    public function moveTo(ClassSession $class, \DateTimeImmutable $day): void
    {
        $this->schoolYear = SchoolYear::current($day);
        $this->weekday = Weekday::from((int) $day->format('N'));
        $this->slotIndex = $class->slotIndex;
        $this->groupNames = mb_substr($class->groupNames(), 0, self::MAX_GROUPS);
        $this->subject = mb_substr($class->subject(), 0, self::MAX_SUBJECT);
    }

    /**
     * Whether a class given on a day is this activity's class — the test every session passes.
     *
     * @param ClassSession       $class a class of the organiser
     * @param \DateTimeImmutable $day   the day it is given
     *
     * @return bool true if it is the same class, on this activity's day of the week, in its course
     */
    public function isHeldIn(ClassSession $class, \DateTimeImmutable $day): bool
    {
        return SchoolYear::current($day) === $this->schoolYear
            && (int) $day->format('N') === $this->weekday->value
            && $class->slotIndex === $this->slotIndex
            && mb_substr($class->groupNames(), 0, self::MAX_GROUPS) === $this->groupNames
            && mb_substr($class->subject(), 0, self::MAX_SUBJECT) === $this->subject;
    }

    /**
     * @return string the course it runs in, as "2026-2027"
     */
    public function getSchoolYear(): string
    {
        return $this->schoolYear;
    }

    /**
     * @return Weekday the day of the week of the class
     */
    public function getWeekday(): Weekday
    {
        return $this->weekday;
    }

    /**
     * @return int the period of the class
     */
    public function getSlotIndex(): int
    {
        return $this->slotIndex;
    }

    /**
     * @return string the class's groups, as shown
     */
    public function getGroupNames(): string
    {
        return $this->groupNames;
    }

    /**
     * @return string the class's subject
     */
    public function getSubject(): string
    {
        return $this->subject;
    }
}
