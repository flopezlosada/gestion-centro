<?php

declare(strict_types=1);

namespace App\Agenda;

use App\Entity\Booking;
use App\Entity\User;
use App\Repository\AcademicYearRepository;
use App\Repository\BookingRepository;
use App\Repository\MeetingRepository;
use App\Repository\PersonalEventRepository;
use App\Repository\TaskRepository;
use App\Repository\TimeSlotRepository;
use App\Util\SchoolYear;

/**
 * Builds a user's personal agenda: their institutional tasks (assigned to them), their private personal
 * events (reminders and appointments), the meetings they are convened to and what they have booked, merged into one timeline and
 * split into time buckets. The single source of this merge/bucket logic, used by the home dashboard (the
 * only page that reads "lo mío" as a merged timeline; the Calendario reads each kind per day instead).
 */
final class PersonalAgenda
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly PersonalEventRepository $events,
        private readonly MeetingRepository $meetings,
        private readonly BookingRepository $bookings,
        private readonly AcademicYearRepository $years,
        private readonly TimeSlotRepository $timeSlots,
    ) {
    }

    /**
     * The user's agenda entries split by time: overdue, today, next 7 days, later, and the done ones
     * apart. Each bucket is ordered chronologically so tasks and events interleave by day. Dates are
     * compared as "YYYY-MM-DD" strings, so the result is timezone-proof.
     *
     * @param User               $user  the owner of the agenda
     * @param \DateTimeImmutable $today the reference day
     *
     * @return array{overdue: AgendaEntry[], today: AgendaEntry[], week: AgendaEntry[], later: AgendaEntry[], done: AgendaEntry[]}
     */
    public function bucketsFor(User $user, \DateTimeImmutable $today): array
    {
        $taskAgenda = $this->tasks->findAgendaFor($user, SchoolYear::current($today));
        // From a month back so a recently-missed reminder still shows as overdue; scoped by owner.
        $eventAgenda = $this->events->findUpcomingFor($user, $today->modify('-1 month'));
        // Meetings only from today onwards, unlike the events above: a meeting that already happened is
        // not "overdue" — you cannot go to it now — and its record (with the acta) lives in "Mis
        // reuniones". Scoped to the meetings the user is part of by the repository.
        $meetingAgenda = $this->meetings->findUpcomingFor($user, $today);

        $entries = [
            ...array_map(AgendaEntry::fromTask(...), $taskAgenda),
            ...array_map(AgendaEntry::fromEvent(...), $eventAgenda),
            ...array_map(AgendaEntry::fromMeeting(...), $meetingAgenda),
            ...array_map(AgendaEntry::fromBooking(...), $this->bookedSpans($user, $today)),
        ];
        usort($entries, static fn (AgendaEntry $a, AgendaEntry $b): int => $a->date <=> $b->date);

        $todayStr = $today->format('Y-m-d');
        $weekStr = $today->modify('+7 days')->format('Y-m-d');
        $buckets = ['overdue' => [], 'today' => [], 'week' => [], 'later' => [], 'done' => []];

        foreach ($entries as $entry) {
            if ($entry->done) {
                $buckets['done'][] = $entry;
                continue;
            }
            $day = $entry->date->format('Y-m-d');
            $bucket = match (true) {
                $day < $todayStr => 'overdue',
                $day === $todayStr => 'today',
                $day <= $weekStr => 'week',
                default => 'later',
            };
            $buckets[$bucket][] = $entry;
        }

        return $buckets;
    }

    /**
     * The viewer's one-off bookings of the next seven days, one line per thing and day. The days of a fixed
     * booking are left out, as in the booking screen's own list ({@see BookingRepository::findUpcomingFor()}):
     * a whole course of rows, and they have their own screen. Nothing further
     * ahead: the home only shows this week, and the booking screen has all of it. The timetable frame is
     * read only when there is something to show, because most days a teacher has booked nothing.
     *
     * @param User               $user  the owner
     * @param \DateTimeImmutable $today the reference day
     *
     * @return list<BookedSpan> the lines, earliest first
     */
    private function bookedSpans(User $user, \DateTimeImmutable $today): array
    {
        $weekStr = $today->modify('+7 days')->format('Y-m-d');
        $coming = array_values(array_filter(
            $this->bookings->findUpcomingFor($user, $today),
            static fn (Booking $b): bool => $b->getDate()->format('Y-m-d') <= $weekStr,
        ));
        if ([] === $coming) {
            return [];
        }

        $frame = $this->timeSlots->lectiveTimesWithFallback($this->years->findBySchoolYear(SchoolYear::current($today)))['slots'];

        return BookedSpan::group($coming, $frame);
    }
}
