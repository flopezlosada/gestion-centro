<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Repository\AbsenceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A teacher being away on a given day. It groups the {@see GuardiaCover} lines generated for each
 * period they would have taught.
 *
 * It deliberately does NOT record WHY the teacher is away. The application organises who covers each
 * class; it is not where staff absences are justified or registered — that stays in the Consejería's
 * own tools. A free-text reason ended up holding exactly what must not live here ("médico", "baja"),
 * so there is no column for it and no way to type one.
 *
 * The absent teacher and date are also snapshotted onto each {@see GuardiaCover} (alongside its group
 * and room), so the parte and every analytics query keep reading per period without a join; those
 * fields are written together with this absence at registration time and never drift.
 *
 * It also records WHICH PERIODS the absence spans ({@see $slotIndexes}), and that is not a detail. Being
 * away used to be inferred from the cover lines, and covers only exist for periods the teacher would
 * have TAUGHT — so a teacher away during a period where they were on guardia left no trace at all, and
 * the rota happily kept the guardia on them or handed them another. Storing the periods makes "who is
 * away at this period" a fact to read instead of a shape to guess.
 *
 * {@see Auditable}, and it has to be: the periods it owns decide who the rota may hand a guardia to, and
 * they are edited AFTER the fact. They used to change without leaving a trace, on an entity every cover
 * of the day hangs off. Its trail is shown on each of those covers' "modificar guardia" screen, which is
 * the only place it would be looked for.
 */
#[ORM\Entity(repositoryClass: AbsenceRepository::class)]
#[ORM\Table(name: 'guardia_absence')]
#[ORM\UniqueConstraint(name: 'UNIQ_guardia_absence', columns: ['absent_teacher_id', 'absence_date'])]
class Absence implements Auditable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The teacher who is away. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'absent_teacher_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $absentTeacher;

    /** The day of the absence. */
    #[ORM\Column(name: 'absence_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    /**
     * The period indexes the absence spans, ascending and without repeats.
     *
     * Every period the teacher is away for, not only the ones that produced a cover: a period where they
     * were on guardia produces none (there is no class to cover) and is exactly the case that used to
     * slip through. An empty list means the day left no trace in the timetable at all.
     *
     * @var list<int>
     */
    #[ORM\Column(name: 'slot_indexes', type: Types::JSON)]
    private array $slotIndexes = [];

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAbsentTeacher(): User
    {
        return $this->absentTeacher;
    }

    public function setAbsentTeacher(User $absentTeacher): static
    {
        $this->absentTeacher = $absentTeacher;

        return $this;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    /**
     * The periods the absence spans, ascending.
     *
     * @return list<int> the period indexes
     */
    public function getSlotIndexes(): array
    {
        return $this->slotIndexes;
    }

    /**
     * Adds periods to the absence, keeping the list sorted and free of repeats.
     *
     * Adds rather than replaces because a day is often registered in two goes — the teacher signs up for
     * the morning and the coordinator adds the afternoon — and the second pass must not erase the first.
     *
     * @param list<int> $slotIndexes the periods to add
     */
    public function addSlotIndexes(array $slotIndexes): static
    {
        $merged = array_values(array_unique(array_merge($this->slotIndexes, $slotIndexes)));
        sort($merged);
        $this->slotIndexes = $merged;

        return $this;
    }

    /**
     * Whether the teacher is away at the given period.
     *
     * @param int $slotIndex the period index
     *
     * @return bool true when the absence spans it
     */
    public function coversSlot(int $slotIndex): bool
    {
        return \in_array($slotIndex, $this->slotIndexes, true);
    }
}
