<?php

declare(strict_types=1);

namespace App\Entity;

use App\Agenda\ClassSession;
use App\Enum\Weekday;
use App\Util\SchoolYear;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An activity held in some of the organiser's classes with one group every week ("1ºA, los martes a 3ª
 * y los jueves a 5ª, grupos interactivos"): it has a session each day of the week one of those classes is
 * given, and only then — not on a holiday, not in a break between terms, not once the course is over.
 *
 * All its classes are with one group, and the ones on the same day follow one another: a family comes
 * once that day, so a double period is one session, from the start of the first class to the end of the
 * last. {@see problemWith()} is the rule; nothing builds or changes the activity without passing it.
 *
 * The classes are remembered by day, period and subject, with the group once, as a {@see LessonPlan}
 * remembers a class, and NOT by a foreign key to the timetable cells: every Peñalara import deletes and
 * recreates them, and would take the activity with them. If the timetable changes and a class is no
 * longer given, it simply has no more sessions, and the staff list says so. It belongs to one course: the
 * same group next year is other pupils and another timetable.
 */
#[ORM\Entity]
class WeeklyFamilyActivity extends FamilyActivity
{
    /** Column widths of the group and of a subject: what a stored class is cut to and compared at. */
    private const int MAX_GROUPS = 160;
    private const int MAX_SUBJECT = 120;

    /** The course it runs in, as "2026-2027". */
    #[ORM\Column(name: 'school_year', length: 9)]
    private string $schoolYear;

    /** The group of its classes as shown ("B1A, B1B"), as {@see ClassSession::groupNames()} gives it. */
    #[ORM\Column(name: 'group_names', length: self::MAX_GROUPS)]
    private string $groupNames;

    /**
     * Its classes, by day of the week then period: the ISO weekday, the period (0-based, the Peñalara index
     * {@see ClassSession::$slotIndex} uses) and the subject as {@see ClassSession::subject()} gives it.
     *
     * @var list<array{weekday: int, slot: int, subject: string}>
     */
    #[ORM\Column(name: 'class_times', type: Types::JSON)]
    private array $classTimes;

    /**
     * @param User                                                         $organizer who publishes it, the teacher of the classes
     * @param string                                                       $title     what it is
     * @param list<array{day: \DateTimeImmutable, class: ClassSession}> $classes   its classes, each as given on one day
     *
     * @throws \InvalidArgumentException if the classes break {@see problemWith()}
     */
    public function __construct(User $organizer, string $title, array $classes)
    {
        parent::__construct($organizer, $title);
        $this->holdIn($classes);
    }

    /**
     * Weekly: one session each week its classes are given.
     */
    public function isWeekly(): bool
    {
        return true;
    }

    /**
     * What is wrong with a choice of classes for one activity, if anything: none, two groups, two courses,
     * or two classes on one day with another in between (the family would come twice, or wait).
     *
     * @param list<array{day: \DateTimeImmutable, class: ClassSession}> $classes the classes, each as given on one day
     *
     * @return string|null why they cannot be one activity, said to the teacher, or null if they can
     */
    public static function problemWith(array $classes): ?string
    {
        if ([] === $classes) {
            return 'Elige al menos una clase.';
        }
        if (\count(array_unique(array_map(static fn (array $c): string => $c['class']->groupNames(), $classes))) > 1) {
            return 'Todas las clases tienen que ser del mismo grupo.';
        }
        if (\count(array_unique(array_map(static fn (array $c): string => SchoolYear::current($c['day']), $classes))) > 1) {
            return 'Todas las clases tienen que ser del mismo curso.';
        }
        $ordinalsByDay = [];
        foreach ($classes as ['day' => $day, 'class' => $class]) {
            $ordinalsByDay[$day->format('N')][] = $class->ordinal;
        }
        foreach ($ordinalsByDay as $ordinals) {
            if (max($ordinals) - min($ordinals) !== \count(array_unique($ordinals)) - 1) {
                return 'Las clases de un mismo día tienen que ir seguidas: ese día la familia viene una vez.';
            }
        }

        return null;
    }

    /**
     * Holds the activity in another choice of the organiser's classes.
     *
     * @param list<array{day: \DateTimeImmutable, class: ClassSession}> $classes the classes, each as given on one day
     *
     * @throws \InvalidArgumentException if the classes break {@see problemWith()}
     */
    public function holdIn(array $classes): void
    {
        $problem = self::problemWith($classes);
        if (null !== $problem) {
            throw new \InvalidArgumentException($problem);
        }
        $this->schoolYear = SchoolYear::current($classes[0]['day']);
        $this->groupNames = mb_substr($classes[0]['class']->groupNames(), 0, self::MAX_GROUPS);
        $times = array_map(static fn (array $c): array => [
            'weekday' => (int) $c['day']->format('N'),
            'slot' => $c['class']->slotIndex,
            'subject' => mb_substr($c['class']->subject(), 0, self::MAX_SUBJECT),
        ], $classes);
        usort($times, static fn (array $a, array $b): int => [$a['weekday'], $a['slot']] <=> [$b['weekday'], $b['slot']]);
        $this->classTimes = $times;
    }

    /**
     * Whether a class given on a day is one of this activity's classes — the test every session passes.
     *
     * @param ClassSession       $class a class of the organiser
     * @param \DateTimeImmutable $day   the day it is given
     *
     * @return bool true if it is one of its classes: its day of the week, period, group and subject, in its course
     */
    public function isHeldIn(ClassSession $class, \DateTimeImmutable $day): bool
    {
        if (SchoolYear::current($day) !== $this->schoolYear || mb_substr($class->groupNames(), 0, self::MAX_GROUPS) !== $this->groupNames) {
            return false;
        }
        $weekday = (int) $day->format('N');
        $subject = mb_substr($class->subject(), 0, self::MAX_SUBJECT);

        return [] !== array_filter($this->classTimes, static fn (array $t): bool => $t['weekday'] === $weekday && $t['slot'] === $class->slotIndex && $t['subject'] === $subject);
    }

    /**
     * @return string the course it runs in, as "2026-2027"
     */
    public function getSchoolYear(): string
    {
        return $this->schoolYear;
    }

    /**
     * @return string the group of its classes, as shown
     */
    public function getGroupNames(): string
    {
        return $this->groupNames;
    }

    /**
     * @return list<array{weekday: Weekday, slot: int, subject: string}> its classes, by day of the week then period
     */
    public function classTimes(): array
    {
        return array_map(static fn (array $t): array => ['weekday' => Weekday::from($t['weekday']), 'slot' => $t['slot'], 'subject' => $t['subject']], $this->classTimes);
    }

    /**
     * @return list<Weekday> the days of the week it has a session, Monday first
     */
    public function weekdays(): array
    {
        return array_map(static fn (int $w): Weekday => Weekday::from($w), array_values(array_unique(array_column($this->classTimes, 'weekday'))));
    }

    /**
     * @return list<string> the subjects of its classes, each once
     */
    public function subjects(): array
    {
        return array_values(array_unique(array_column($this->classTimes, 'subject')));
    }
}
