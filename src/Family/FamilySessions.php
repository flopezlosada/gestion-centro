<?php

declare(strict_types=1);

namespace App\Family;

use App\Agenda\ClassSession;
use App\Agenda\MyClasses;
use App\Entity\FamilyActivity;
use App\Entity\User;
use App\Entity\OneOffFamilyActivity;
use App\Entity\WeeklyFamilyActivity;
use App\Enum\Weekday;
use App\Repository\FamilyActivityRepository;
use App\Util\SchoolYear;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Works out the sessions of the activities open to families: when each one actually takes place.
 *
 * A one-off activity is its own session. A weekly one takes place on the days its organiser really gives
 * that class — read from {@see MyClasses}, so holidays, breaks between terms, the edges of the course and
 * exam weeks that replace lessons are all respected without anybody cancelling a session by hand.
 *
 * Every session a family can sign up for comes from here, so a family can never sign up for a day the
 * activity does not take place.
 *
 * Sessions are never stored, past ones included: they are the timetable as it is now. If a new import
 * moves the class (another teacher, another period), its past sessions leave the calendar too; their
 * signups stay in the database, out of sight.
 */
final class FamilySessions implements ResetInterface
{
    /** How far ahead the staff list looks for a weekly activity's next session: past a Christmas break. */
    private const int NEXT_SESSION_WEEKS = 5;

    /**
     * An organiser's classes per day, read once per request: showing the current week, the staff list
     * asks for the same day twice (the calendar and each activity's next session), and two activities of
     * one organiser on the same day share it. Not a range read: {@see MyClasses::between()} walks every day of it.
     *
     * @var array<string, list<ClassSession>> "userId|Y-m-d" → that day's classes
     */
    private array $classesByDay = [];

    public function __construct(
        private readonly FamilyActivityRepository $activities,
        private readonly MyClasses $classes,
    ) {
    }

    /**
     * Forgets the classes read, so a worker serving request after request never shows a timetable that
     * has since changed.
     */
    public function reset(): void
    {
        $this->classesByDay = [];
    }

    /**
     * The Monday of the week a day falls in, at midnight.
     *
     * @param \DateTimeImmutable $day any day
     *
     * @return \DateTimeImmutable the Monday of its week
     */
    public static function mondayOf(\DateTimeImmutable $day): \DateTimeImmutable
    {
        return $day->setTime(0, 0)->modify('-'.((int) $day->format('N') - 1).' days');
    }

    /**
     * The sessions of one school week, Monday to Friday, each day earliest first. Every weekday is a key,
     * with or without sessions, so a calendar can draw the empty days too.
     *
     * The one-offs come in one query and the weekly activities in another; then each weekly activity
     * reads its organiser's day (a few queries each, {@see MyClasses::on()}) — a cost per organiser and
     * day, not per family or session, and there are only ever a handful of activities.
     *
     * @param \DateTimeImmutable $monday the Monday of the week
     *
     * @return array<string, list<FamilySession>> "Y-m-d" → that day's sessions
     */
    public function week(\DateTimeImmutable $monday): array
    {
        $days = array_map(
            static fn (Weekday $w): \DateTimeImmutable => $monday->modify('+'.($w->value - 1).' days'),
            Weekday::schoolWeek(),
        );
        $friday = end($days);
        $sessions = [
            ...array_map(
                static fn (OneOffFamilyActivity $a): FamilySession => $a->session(),
                $this->activities->findOneOffsBetween($monday, $friday->modify('+1 day')),
            ),
            ...array_filter(array_merge(...array_map(
                fn (WeeklyFamilyActivity $a): array => array_map(
                    fn (Weekday $w): ?FamilySession => $this->weeklySessionOn($a, $monday->modify('+'.($w->value - 1).' days')),
                    $a->weekdays(),
                ),
                $this->activities->findWeeklyIn(array_unique([SchoolYear::current($monday), SchoolYear::current($friday)])),
            ))),
        ];
        usort($sessions, static fn (FamilySession $a, FamilySession $b): int => [$a->startsAt, $a->activity->getTitle()] <=> [$b->startsAt, $b->activity->getTitle()]);

        $byDay = array_fill_keys(array_map(static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d'), $days), []);
        foreach ($sessions as $session) {
            $byDay[$session->day->format('Y-m-d')][] = $session;
        }

        return $byDay;
    }

    /**
     * The session of an activity on a given day, if it takes place then — what a signup is checked against.
     *
     * @param FamilyActivity     $activity the activity
     * @param \DateTimeImmutable $day      the day
     *
     * @return FamilySession|null its session that day, or null if it does not take place then
     */
    public function sessionOn(FamilyActivity $activity, \DateTimeImmutable $day): ?FamilySession
    {
        if ($activity instanceof OneOffFamilyActivity) {
            $session = $activity->session();

            return $session->day->format('Y-m-d') === $day->format('Y-m-d') ? $session : null;
        }
        \assert($activity instanceof WeeklyFamilyActivity);

        return $this->weeklySessionOn($activity, $day);
    }

    /**
     * The next session of a weekly activity not started yet, looking a few weeks ahead.
     *
     * @param WeeklyFamilyActivity $activity the activity
     * @param \DateTimeImmutable   $now      the instant to look from
     *
     * @return FamilySession|null the next session, or null if there is none in the next weeks — over, or
     *                            its class no longer in the organiser's timetable
     */
    public function nextOf(WeeklyFamilyActivity $activity, \DateTimeImmutable $now): ?FamilySession
    {
        $monday = self::mondayOf($now);
        foreach (range(0, self::NEXT_SESSION_WEEKS - 1) as $week) {
            foreach ($activity->weekdays() as $weekday) {
                $day = $monday->modify('+'.$week.' weeks +'.($weekday->value - 1).' days');
                $session = $day >= $now->setTime(0, 0) ? $this->weeklySessionOn($activity, $day) : null;
                if (null !== $session && $session->isOpenAt($now)) {
                    return $session;
                }
            }
        }

        return null;
    }

    /**
     * The classes a teacher gives in the coming weeks, each once (by day of the week and period), with a
     * day it is given on: what a teacher picks a weekly activity's class from.
     *
     * @param User               $teacher the teacher
     * @param \DateTimeImmutable $today   the day to look from
     *
     * @return array<string, array{day: \DateTimeImmutable, class: ClassSession}> keyed "weekday-period", by day of the week then period
     */
    public function classesOf(User $teacher, \DateTimeImmutable $today): array
    {
        $options = [];
        foreach ($this->classes->between($teacher, $today, $today->modify('+'.(self::NEXT_SESSION_WEEKS * 7 - 1).' days')) as $date => $sessions) {
            $day = new \DateTimeImmutable($date, $today->getTimezone());
            foreach ($sessions as $class) {
                $options[$day->format('N').'-'.$class->slotIndex] ??= ['day' => $day, 'class' => $class];
            }
        }
        uksort($options, static fn (string $a, string $b): int => array_map('intval', explode('-', $a)) <=> array_map('intval', explode('-', $b)));

        return $options;
    }

    /**
     * The session of a weekly activity on a day, if its organiser gives any of its classes that day: from
     * the start of the first to the end of the last (they follow one another, a double period being one
     * visit), in their rooms. Classes without times are left out: they cannot tell a family when to come.
     *
     * @param WeeklyFamilyActivity $activity the activity
     * @param \DateTimeImmutable   $day      the day
     *
     * @return FamilySession|null its session that day, or null
     */
    private function weeklySessionOn(WeeklyFamilyActivity $activity, \DateTimeImmutable $day): ?FamilySession
    {
        $organizer = $activity->getOrganizer();
        if (null === $organizer) {
            return null;
        }
        $classes = $this->firstRun(array_values(array_filter($this->classesOn($organizer, $day), static fn (ClassSession $c): bool => $activity->isHeldIn($c, $day))));
        $starts = array_filter(array_map(static fn (ClassSession $c): ?\DateTimeImmutable => $c->startsAt, $classes));
        $ends = array_filter(array_map(static fn (ClassSession $c): ?\DateTimeImmutable => $c->endsAt, $classes));
        if ([] === $starts || [] === $ends) {
            return null;
        }
        $rooms = array_values(array_unique(array_merge(...array_map(static fn (ClassSession $c): array => $c->rooms(), $classes))));

        // Always the classes' own rooms: a weekly activity has no place of its own to set.
        return new FamilySession($activity, min($starts), max($ends), [] !== $rooms ? implode(', ', $rooms) : null);
    }

    /**
     * The first of a day's classes and those right after it. They were picked one after another, but a new
     * timetable may give a period in between to another group: the session must not take that one in.
     *
     * @param list<ClassSession> $classes classes of one day, earliest first
     *
     * @return list<ClassSession> the first run of them, one after another
     */
    private function firstRun(array $classes): array
    {
        $run = [];
        foreach ($classes as $class) {
            if ([] !== $run && $class->ordinal !== end($run)->ordinal + 1) {
                break;
            }
            $run[] = $class;
        }

        return $run;
    }

    /**
     * An organiser's classes on a day, from {@see $classesByDay} once read.
     *
     * @param User               $organizer the organiser
     * @param \DateTimeImmutable $day       the day
     *
     * @return list<ClassSession> the day's classes, earliest first
     */
    private function classesOn(User $organizer, \DateTimeImmutable $day): array
    {
        return $this->classesByDay[$organizer->getId().'|'.$day->format('Y-m-d')] ??= $this->classes->on($organizer, $day);
    }
}
