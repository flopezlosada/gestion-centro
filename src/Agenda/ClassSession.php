<?php

declare(strict_types=1);

namespace App\Agenda;

use App\Space\EffectiveLesson;

/**
 * One class a teacher gives on a given day, at one period: the lessons of that period as they really
 * happen (room included) and, when the course's marco horario is known, when it starts and ends.
 *
 * A PERIOD and not a timetable cell, because Peñalara stores one cell per group: a subject taught to a
 * grouping of several groups at once is several cells at the same hour — and the same group can come
 * twice under an internal variant ("B1A" and "B1A~212302"). The teacher gives one class, so it is one
 * session; same folding as the "apuntar ausencia" list of the guardia parte.
 */
final readonly class ClassSession
{
    /**
     * @param non-empty-list<EffectiveLesson> $lessons   the period's lessons (one per timetable cell)
     * @param int                             $slotIndex the period of the day
     * @param int                             $ordinal   the period's place among the day's teaching periods (1 = "1ª")
     * @param \DateTimeImmutable|null         $startsAt  when it starts that day, or null without a timetable frame
     * @param \DateTimeImmutable|null         $endsAt    when it ends that day, or null without a timetable frame
     */
    public function __construct(
        public array $lessons,
        public int $slotIndex,
        public int $ordinal,
        public ?\DateTimeImmutable $startsAt,
        public ?\DateTimeImmutable $endsAt,
    ) {
    }

    /**
     * The groups in the class as the staff name them: Peñalara's internal "~NNNNNN" variant of a group
     * dropped, each group once, SORTED. The order matters: the groups joined are what identifies "the same
     * class" from one week to the next, and the timetable does not order the cells of one period.
     *
     * @return list<string> the group names, sorted
     */
    public function groups(): array
    {
        $groups = $this->distinct(static fn (EffectiveLesson $l): ?string => null !== $l->entry->getGroupName()
            ? preg_replace('/~\d+$/', '', $l->entry->getGroupName())
            : null);
        sort($groups);

        return $groups;
    }

    /**
     * @return list<string> the subjects taught, each once (almost always one)
     */
    public function subjects(): array
    {
        return $this->distinct(static fn (EffectiveLesson $l): ?string => $l->entry->getSubjectName());
    }

    /**
     * @return list<string> the rooms the class is really held in, each once
     */
    public function rooms(): array
    {
        return $this->distinct(static fn (EffectiveLesson $l): ?string => $l->roomName());
    }

    /**
     * Whether an approved space plan moved the class, so a screen can say "aula cambiada".
     */
    public function isRelocated(): bool
    {
        return [] !== array_filter($this->lessons, static fn (EffectiveLesson $l): bool => $l->isRelocated());
    }

    /**
     * The non-empty values a reader gives for the lessons, each once, in the lessons' order.
     *
     * @param callable(EffectiveLesson): ?string $read what to read from each lesson
     *
     * @return list<string> the distinct values
     */
    private function distinct(callable $read): array
    {
        return array_values(array_unique(array_filter(
            array_map($read, $this->lessons),
            static fn (?string $v): bool => null !== $v && '' !== $v,
        )));
    }
}
