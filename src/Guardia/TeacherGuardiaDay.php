<?php

declare(strict_types=1);

namespace App\Guardia;

use App\Entity\GuardiaCover;

/**
 * Reads a teacher's covers for ONE day as the view-model every surface of theirs needs: each cover
 * with its period times, whether it is already over, the countdown to the next one still to cover,
 * and the day's tallies.
 *
 * It exists because two screens were answering "which is your next guardia?" on their own — the
 * "mis guardias" page and the home hero — and two answers to that question is one too many: the hero
 * decided "still current" from {@code endsAt >= now} while the page decided "done" from
 * {@code endsAt < now}, and nothing but luck kept the pair complementary. Now both read this.
 *
 * A cover counts as done only when its period end time is KNOWN and already past: with no imported
 * timetable the times are unknown, so nothing can be called done and every cover stays pending —
 * better a stale "pendiente" than falsely telling a teacher their guardia is over.
 */
final readonly class TeacherGuardiaDay
{
    /**
     * The day view for a teacher's covers.
     *
     * @param GuardiaCover[]                                                              $covers    the covers of that single day, earliest period first
     * @param array<int, array{startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}> $slotTimes period times by slot index (see {@see \App\Repository\ScheduleEntryRepository::slotTimes()})
     * @param \DateTimeImmutable                                                          $now       the current instant
     *
     * @return array{
     *     items: list<array{cover: GuardiaCover, done: bool, startsAt: ?\DateTimeImmutable, endsAt: ?\DateTimeImmutable, minutesUntil: ?int}>,
     *     next: ?int,
     *     counts: array{assigned: int, pending: int, withTask: int}
     * } the covers as rows, the index of the next one still to cover, and the day's tallies
     */
    public function forDay(array $covers, array $slotTimes, \DateTimeImmutable $now): array
    {
        $items = [];
        $next = null;
        $pending = 0;
        $withTask = 0;

        foreach (array_values($covers) as $i => $cover) {
            $times = $slotTimes[$cover->getSlotIndex()] ?? null;
            $startsAt = $times['startsAt'] ?? null;
            $endsAt = $times['endsAt'] ?? null;
            $done = null !== $endsAt && $endsAt < $now;

            if (!$done) {
                ++$pending;
                $next ??= $i; // the first cover not yet over is the protagonist ("tu próxima guardia")
            }
            if ($cover->hasTask()) {
                ++$withTask;
            }

            $items[] = [
                'cover' => $cover,
                'done' => $done,
                'startsAt' => $startsAt,
                'endsAt' => $endsAt,
                'minutesUntil' => null !== $startsAt && $startsAt > $now
                    ? intdiv($startsAt->getTimestamp() - $now->getTimestamp(), 60)
                    : null,
            ];
        }

        return [
            'items' => $items,
            'next' => $next,
            'counts' => ['assigned' => \count($items), 'pending' => $pending, 'withTask' => $withTask],
        ];
    }

    /**
     * Whether a period of ANY day is already over: a past day is, a future one is not, and today it is
     * over only when its end time is known and past — the same rule as {@see self::forDay()}, for a
     * screen that is not looking at today (the parte can be opened on any date).
     *
     * The period times are only TIMES: {@see \App\Repository\ScheduleEntryRepository::slotTimes()} dates
     * them with the process's today, not the clock's, so the end is put back on the given day before
     * comparing — otherwise a mocked or a late clock would compare against the wrong day.
     *
     * @param \DateTimeImmutable                                                          $date      the day of the period
     * @param int                                                                         $slotIndex the period
     * @param array<int, array{startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable}> $slotTimes period times by slot index
     * @param \DateTimeImmutable                                                          $now       the current instant
     *
     * @return bool true once nothing done for that period can still serve anyone
     */
    public function isSlotOver(\DateTimeImmutable $date, int $slotIndex, array $slotTimes, \DateTimeImmutable $now): bool
    {
        // El día y las horas del horario son de la zona del centro; el reloj puede venir en otra (UTC).
        $now = $now->setTimezone($date->getTimezone());
        $day = $date->format('Y-m-d');
        $today = $now->format('Y-m-d');
        if ($day !== $today) {
            return $day < $today;
        }

        $endsAt = $slotTimes[$slotIndex]['endsAt'] ?? null;

        return null !== $endsAt
            && new \DateTimeImmutable($day.' '.$endsAt->format('H:i:s'), $date->getTimezone()) < $now;
    }
}
