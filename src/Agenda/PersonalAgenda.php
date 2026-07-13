<?php

declare(strict_types=1);

namespace App\Agenda;

use App\Entity\User;
use App\Repository\PersonalEventRepository;
use App\Repository\TaskRepository;
use App\Util\SchoolYear;

/**
 * Builds a user's personal agenda: their institutional tasks (assigned to them) and their private
 * personal events (reminders and appointments) merged into one timeline and split into time buckets.
 * The single source of this merge/bucket logic, shared by the home dashboard and the agenda page.
 */
final class PersonalAgenda
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly PersonalEventRepository $events,
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

        $entries = [
            ...array_map(AgendaEntry::fromTask(...), $taskAgenda),
            ...array_map(AgendaEntry::fromEvent(...), $eventAgenda),
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
}
