<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\Meeting;
use App\Entity\MeetingGroup;
use App\Entity\MeetingType;
use App\Entity\TimeSlot;
use App\Entity\User;
use App\Enum\EventReminderOffset;
use App\Enum\Weekday;
use App\Repository\AcademicYearRepository;
use App\Repository\MeetingGroupRepository;
use App\Repository\MeetingRepository;
use App\Repository\TimeSlotRepository;
use App\Util\CalendarDate;
use App\Util\SchoolYear;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates each week's meeting of the groups that repeat weekly ({@see MeetingGroup::generatesMeetings()}),
 * a week ahead, so the tutor meeting, the department meetings and the CCP stop having to be convened by
 * hand every week — the centre's request.
 *
 * Each group remembers the last day it was generated for ({@see MeetingGroup::getGeneratedThrough()}) and
 * only ever generates after it. That is what makes it safe to run every day: nothing is generated twice,
 * and an occurrence the convener DELETED (a holiday, a week they do not meet) is never brought back.
 * The unique (group, start) index in the database is only the backstop.
 *
 * Only on teaching days of an existing course ({@see SchoolCalendar}, bounded by the course's own first
 * and last day, which the calendar alone does not know), at the period's time in that course's marco
 * horario. When a day cannot be placed — no course yet, or the period does not exist in its timetable —
 * the group stops there without moving its mark, so the day is retried once the data is in instead of
 * being skipped for good.
 *
 * Deliberately NO convocation notice to the people convened. These meetings are fixed in everybody's
 * timetable already; announcing 37 of them every week would bury every notice that matters. They appear
 * in the agenda of everyone convened, with the group's place and reminder. Only the CONVENER is told,
 * once per meeting, that it exists and still lacks an agenda ({@see MeetingNotifier::notifyAgendaPending()}).
 *
 * Each meeting is a copy made on the day it is generated, and an edit of the group also reaches the
 * meetings already created and not yet started ({@see applySeriesChange()}): someone added today is
 * convened to tomorrow's meeting. Past meetings are never touched.
 */
final class RecurringMeetingGenerator
{
    /** How far ahead meetings exist: a week, so everybody sees next week's meetings in their agenda. */
    public const int LOOKAHEAD_DAYS = 7;

    /** @var array<string, bool> teaching-day answers, by Y-m-d, for this run */
    private array $lective = [];

    /** @var array<string, AcademicYear|null> course by school-year string, for this run */
    private array $years = [];

    /** @var array<int, array<int, TimeSlot>> the marco horario by course id and period index, for this run */
    private array $slots = [];

    public function __construct(
        private readonly MeetingGroupRepository $groups,
        private readonly MeetingRepository $meetings,
        private readonly AcademicYearRepository $academicYears,
        private readonly TimeSlotRepository $timeSlots,
        private readonly SchoolCalendar $calendar,
        private readonly EntityManagerInterface $entityManager,
        private readonly MeetingNotifier $notifier,
    ) {
    }

    /**
     * Carries an edit of the group to its meetings already created and not yet started: the next meeting
     * reflects the change even if it is tomorrow. Past meetings are never touched.
     *
     * Field by field, and only where the meeting still had the group's PREVIOUS value: whatever the
     * convener changed by hand on one meeting (the place of one week, a guest) stays as they decided.
     * Members travel as a change too (who joined, who left), never as a replacement of the list. The
     * convener is the exception: a meeting cannot change it by hand, so it always follows the group's.
     *
     * A new day or period moves each meeting within its own week and warns the people convened, as moving
     * a meeting by hand does. When that week's new day has already gone by, is not a teaching day or has
     * no such period, the meeting of that week is cancelled, with its notice. So is every upcoming one
     * when the group stops repeating or is retired. A new convener gets the agenda notice of each meeting
     * they now call.
     *
     * Saves and notifies itself: call it after the group is saved.
     *
     * @param MeetingGroup                                                                                                                                                                  $group  the group just edited
     * @param array{name: string, type: MeetingType|null, convener: User|null, place: string|null, reminder: EventReminderOffset|null, weekday: Weekday|null, slot: int|null, members: list<User>} $before the group as it was before the edit ({@see MeetingGroup::seriesShape()})
     * @param \DateTimeImmutable                                                                                                                                                            $now    the reference moment (injectable in tests)
     *
     * @return int how many upcoming meetings changed (cancelled ones included)
     */
    public function applySeriesChange(MeetingGroup $group, array $before, \DateTimeImmutable $now): int
    {
        $after = $group->seriesShape();
        $upcoming = $this->meetings->findUpcomingInGroup($group, $now);
        if ([] === $upcoming) {
            return 0;
        }
        $this->lective = $this->years = $this->slots = [];
        $added = array_values(array_filter($after['members'], static fn (User $u): bool => !\in_array($u, $before['members'], true)));
        $removed = array_values(array_filter($before['members'], static fn (User $u): bool => !\in_array($u, $after['members'], true)));
        $stops = !$group->isActive() || null === $after['weekday'] || null === $after['slot'];
        $reschedules = $before['weekday'] !== $after['weekday'] || $before['slot'] !== $after['slot'];

        $changed = $cancelled = $moved = $handedOver = [];
        foreach ($upcoming as $meeting) {
            $original = $this->fingerprint($meeting);
            $onSchedule = $this->isOnSchedule($meeting, $before);

            // Lo que cancela se decide ANTES de tocar nada: el aviso de cancelación va a quienes tenían la
            // reunión, no a la lista ya retocada por este mismo cambio. Un grupo que deja de repetirse o se
            // retira cancela todas sus reuniones futuras, también la que su convocante movió a mano.
            if ($stops) {
                $cancelled[] = $meeting;
                continue;
            }
            $timeMoved = false;
            if ($reschedules && $onSchedule) {
                if (!$this->moveWithinItsWeek($meeting, $after['weekday'], (int) $after['slot'], $now)) {
                    $cancelled[] = $meeting;
                    continue;
                }
                $timeMoved = true;
            }
            if ($meeting->getTitle() === $before['name']) {
                $meeting->setTitle($after['name']);
            }
            if ($meeting->getType() === $before['type'] && $before['type'] !== $after['type']) {
                $meeting->setType($after['type'])->setMinutesApprovalRequired($after['type']?->isMinutesApprovalRequired() ?? false);
            }
            if ($meeting->getReminder() === $before['reminder'] && $before['reminder'] !== $after['reminder']) {
                $meeting->setReminder($after['reminder']);
            }
            $placeMoved = false;
            if ($meeting->getPlace() === $before['place'] && $before['place'] !== $after['place']) {
                // Poner lugar donde no lo había no mueve a nadie; cambiarlo o quitarlo, sí.
                $placeMoved = null !== $before['place'];
                $meeting->setPlace($after['place']);
            }
            // El convocante NO sigue la regla del valor anterior: una reunión no permite cambiarlo a mano,
            // así que si no coincide con el del grupo es que se quedó atrás, nunca una decisión de quien la
            // convoca. Siempre sigue al del grupo.
            $previousConvener = $meeting->getConvener();
            if (null !== $after['convener'] && $previousConvener !== $after['convener']) {
                $meeting->handOverTo($after['convener']);
                // Quien convocaba y sigue en el grupo pasa a ser un convocado más.
                if (null !== $previousConvener && \in_array($previousConvener, $after['members'], true) && $previousConvener->isActive()) {
                    $meeting->addAttendee($previousConvener);
                }
                $handedOver[] = $meeting;
            }
            foreach ($added as $person) {
                if ($person !== $meeting->getConvener() && $person->isActive()) {
                    $meeting->addAttendee($person);
                }
            }
            array_map($meeting->removeAttendee(...), $removed);

            if ($placeMoved || $timeMoved) {
                $moved[] = $meeting;
            }
            if ($this->fingerprint($meeting) !== $original) {
                $changed[] = $meeting;
            }
        }

        // Avisar de la cancelación ANTES de borrar: después no queda de dónde sacar a los convocados.
        foreach ($cancelled as $meeting) {
            $this->notifier->notifyCancelled($meeting, array_values($meeting->getAttendees()->toArray()));
            $this->entityManager->remove($meeting);
        }
        $this->entityManager->flush();
        foreach ($moved as $meeting) {
            $this->notifier->notifyRescheduled($meeting, array_values($meeting->getAttendees()->toArray()));
        }
        $this->notifier->notifyAgendaPending(array_values(array_filter($handedOver, static fn (Meeting $m): bool => !\in_array($m, $cancelled, true))));

        return \count($changed) + \count($cancelled);
    }

    /**
     * Whether a meeting still sits where the group put it: its day of the week and the start of the
     * period it was generated at. One the convener moved by hand is theirs, and a change of the group's
     * day or period does not drag it along.
     *
     * @param Meeting                                                    $meeting the meeting
     * @param array{weekday: Weekday|null, slot: int|null}               $before  the group's day and period before the edit
     */
    private function isOnSchedule(Meeting $meeting, array $before): bool
    {
        if (null === $before['weekday'] || null === $before['slot'] || (int) $meeting->getStartAt()->format('N') !== $before['weekday']->value) {
            return false;
        }
        $day = $meeting->getStartAt()->setTime(0, 0);
        $year = $this->yearOf($day);
        $slot = null !== $year ? $this->slotFor($year, $before['slot']) : null;

        return null !== $slot && $meeting->getStartAt()->format('H:i') === $slot->getStartsAt()->format('H:i');
    }

    /**
     * Moves a meeting to another day and period of the same week (Monday to Friday of its own week).
     *
     * @param Meeting            $meeting   the meeting
     * @param Weekday            $weekday   the new day
     * @param int                $slotIndex the new period
     * @param \DateTimeImmutable $now       the reference moment
     *
     * @return bool false when it cannot be held there: that day already went by, is not a teaching day,
     *              or has no such period
     */
    private function moveWithinItsWeek(Meeting $meeting, Weekday $weekday, int $slotIndex, \DateTimeImmutable $now): bool
    {
        $start = $meeting->getStartAt();
        $day = $start->setTime(0, 0)->modify(sprintf('%+d days', $weekday->value - (int) $start->format('N')));
        $year = $this->yearOf($day);
        $slot = null !== $year ? $this->slotFor($year, $slotIndex) : null;
        if (null === $year || null === $slot || !$this->isTeachingDay($year, $day)) {
            return false;
        }
        $newStart = CalendarDate::at($day, $slot->getStartsAt());
        if ($newStart <= $now) {
            return false;
        }
        $meeting->setStartAt($newStart)->setEndAt(CalendarDate::at($day, $slot->getEndsAt()));

        return true;
    }

    /**
     * What of a meeting this sweep may change, to tell whether an edit of the group reached it.
     *
     * @param Meeting $meeting the meeting
     *
     * @return string a comparable fingerprint
     */
    private function fingerprint(Meeting $meeting): string
    {
        $ids = array_map(static fn (User $u): int => (int) $u->getId(), $meeting->getAttendees()->toArray());
        sort($ids);

        return json_encode([
            $meeting->getTitle(), $meeting->getType()?->getId(), $meeting->getConvener()?->getId(), $meeting->getPlace(),
            $meeting->getReminder()?->value, $meeting->getStartAt()->format('c'), $ids,
        ], \JSON_THROW_ON_ERROR);
    }

    /**
     * Generates the meetings due from today up to {@see LOOKAHEAD_DAYS} ahead. A meeting whose hour has
     * already gone by today is not created: it would land in the agenda as a past meeting nobody held.
     *
     * @param \DateTimeImmutable $now the reference moment (injectable in tests)
     *
     * @return array{created: int, stuck: list<string>} how many meetings were created, and the groups that could not be placed on some day
     */
    public function generate(\DateTimeImmutable $now): array
    {
        $this->lective = $this->years = $this->slots = [];
        $today = $now->setTime(0, 0);
        $horizon = $today->modify(sprintf('+%d days', self::LOOKAHEAD_DAYS));
        $created = [];
        $stuck = [];

        foreach ($this->groups->findGenerating() as $group) {
            $mark = $group->getGeneratedThrough();
            $day = null !== $mark && $mark >= $today ? $mark->modify('+1 day') : $today;

            for (; $day <= $horizon; $day = $day->modify('+1 day')) {
                if ((int) $day->format('N') !== $group->getWeekday()?->value) {
                    $group->markGeneratedThrough($day);
                    continue;
                }

                $year = $this->yearOf($day);
                $slot = null !== $year ? $this->slotFor($year, (int) $group->getSlotIndex()) : null;
                if (null === $year || null === $slot) {
                    $stuck[] = $group->getName();
                    break;
                }

                if (!$this->isTeachingDay($year, $day)) {
                    $group->markGeneratedThrough($day);
                    continue;
                }

                $meeting = CalendarDate::at($day, $slot->getStartsAt()) > $now ? $this->create($group, $day, $slot) : null;
                if (null !== $meeting) {
                    $created[] = $meeting;
                }
                $group->markGeneratedThrough($day);
            }

            // Group by group: one that cannot be written (a constraint nobody foresaw) must not take the
            // meetings already generated for the others down with it.
            $this->entityManager->flush();
        }
        // Después de guardarlas todas: el aviso a quien convoca habla de reuniones que ya existen.
        $this->notifier->notifyAgendaPending($created);

        return ['created' => \count($created), 'stuck' => $stuck];
    }

    /**
     * Creates one meeting of a group, unless it somehow already exists.
     *
     * @param MeetingGroup       $group the group
     * @param \DateTimeImmutable $day   the day
     * @param TimeSlot           $slot  the period it is held in
     *
     * @return Meeting|null the meeting created, or null when it already existed
     */
    private function create(MeetingGroup $group, \DateTimeImmutable $day, TimeSlot $slot): ?Meeting
    {
        $convener = $group->getConvener();
        \assert($convener instanceof User);
        $startAt = CalendarDate::at($day, $slot->getStartsAt());
        if (null !== $this->meetings->findOneBy(['meetingGroup' => $group, 'startAt' => $startAt])) {
            return null;
        }

        $type = $group->getType();
        $meeting = (new Meeting($convener, $group->getName(), $startAt))
            ->setType($type)
            ->setEndAt(CalendarDate::at($day, $slot->getEndsAt()))
            ->setPlace($group->getPlace())
            ->setReminder($group->getReminder())
            ->setMinutesApprovalRequired($type?->isMinutesApprovalRequired() ?? false)
            ->setMeetingGroup($group);
        // Quien convoca no se convoca a sí mismo: es lo mismo que hace el formulario, que no le ofrece.
        $meeting->syncAttendees(array_values(array_filter(
            $group->getMembers()->toArray(),
            static fn (User $member): bool => $member !== $convener && $member->isActive(),
        )));
        $this->entityManager->persist($meeting);

        return $meeting;
    }

    /**
     * Whether a day is a teaching day of its course: within its first and last day, and neither a
     * weekend nor a registered non-teaching day.
     *
     * @param AcademicYear       $year the course the day belongs to
     * @param \DateTimeImmutable $day  the day
     *
     * @return bool true when meetings may be held
     */
    private function isTeachingDay(AcademicYear $year, \DateTimeImmutable $day): bool
    {
        $key = $day->format('Y-m-d');
        if (!isset($this->lective[$key])) {
            $this->lective[$key] = $day >= $year->getYearStart()->setTime(0, 0)
                && $day <= $year->getYearEnd()->setTime(0, 0)
                && $this->calendar->isLective($day);
        }

        return $this->lective[$key];
    }

    /**
     * The period of a course's marco horario a meeting is held in.
     *
     * @param AcademicYear $year      the course
     * @param int          $slotIndex the period ordinal
     *
     * @return TimeSlot|null the period, or null when that course has no such period
     */
    private function slotFor(AcademicYear $year, int $slotIndex): ?TimeSlot
    {
        $id = (int) $year->getId();
        if (!isset($this->slots[$id])) {
            $this->slots[$id] = [];
            foreach ($this->timeSlots->findByYear($year) as $slot) {
                $this->slots[$id][$slot->getSlotIndex()] = $slot;
            }
        }

        return $this->slots[$id][$slotIndex] ?? null;
    }

    /**
     * The course a day belongs to, if it exists.
     *
     * @param \DateTimeImmutable $day the day
     *
     * @return AcademicYear|null the course
     */
    private function yearOf(\DateTimeImmutable $day): ?AcademicYear
    {
        $schoolYear = SchoolYear::current($day);
        if (!\array_key_exists($schoolYear, $this->years)) {
            $this->years[$schoolYear] = $this->academicYears->findBySchoolYear($schoolYear);
        }

        return $this->years[$schoolYear];
    }
}
