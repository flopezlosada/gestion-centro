<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AcademicYear;
use App\Entity\Meeting;
use App\Entity\MeetingGroup;
use App\Entity\TimeSlot;
use App\Entity\User;
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
 * Deliberately NO convocation notice. These meetings are fixed in everybody's timetable already;
 * announcing 37 of them every week would bury every notice that matters. They appear in the agenda of
 * everyone convened, and the meeting reminder, if its convener sets one, still works.
 *
 * Each meeting is a copy made on the day it is generated: changing the group later (a member added,
 * another period) affects the weeks not yet generated, never one already in the agenda.
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
    ) {
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
        $created = 0;
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

                if (CalendarDate::at($day, $slot->getStartsAt()) > $now && $this->create($group, $day, $slot)) {
                    ++$created;
                }
                $group->markGeneratedThrough($day);
            }

            // Group by group: one that cannot be written (a constraint nobody foresaw) must not take the
            // meetings already generated for the others down with it.
            $this->entityManager->flush();
        }

        return ['created' => $created, 'stuck' => $stuck];
    }

    /**
     * Creates one meeting of a group, unless it somehow already exists.
     *
     * @param MeetingGroup       $group the group
     * @param \DateTimeImmutable $day   the day
     * @param TimeSlot           $slot  the period it is held in
     *
     * @return bool true when a meeting was created
     */
    private function create(MeetingGroup $group, \DateTimeImmutable $day, TimeSlot $slot): bool
    {
        $convener = $group->getConvener();
        \assert($convener instanceof User);
        $startAt = CalendarDate::at($day, $slot->getStartsAt());
        if (null !== $this->meetings->findOneBy(['meetingGroup' => $group, 'startAt' => $startAt])) {
            return false;
        }

        $type = $group->getType();
        $meeting = (new Meeting($convener, $group->getName(), $startAt))
            ->setType($type)
            ->setEndAt(CalendarDate::at($day, $slot->getEndsAt()))
            ->setMinutesApprovalRequired($type?->isMinutesApprovalRequired() ?? false)
            ->setMeetingGroup($group);
        // Quien convoca no se convoca a sí mismo: es lo mismo que hace el formulario, que no le ofrece.
        $meeting->syncAttendees(array_values(array_filter(
            $group->getMembers()->toArray(),
            static fn (User $member): bool => $member !== $convener && $member->isActive(),
        )));
        $this->entityManager->persist($meeting);

        return true;
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
