<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TimeSlotKind;
use App\Repository\TimeSlotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One period of a course's marco horario: the shape of the school day, independent of who is teaching.
 * Imported from the planificador's {@code <marcoHorario>} alongside the timetable itself.
 *
 * It exists because the day's periods cannot be recovered from {@see ScheduleEntry}: those rows only
 * cover periods somebody occupies, and the two recreos hold no activity whatsoever (verified on the
 * centre's own export — zero activities in either break tramo). The break duty rota
 * ({@see BreakDutyAssignment}) needs precisely those periods, with their real times, so they are stored
 * here rather than hard-coded — the times are the centre's, and they change when its timetable does.
 *
 * Like {@see ScheduleEntry} this is imported reference data, replaced wholesale on each import of the
 * course, and therefore NOT {@see \App\Contract\Auditable}: the audit trail follows hand edits, not the
 * bulk load. One row per (course, period index): the centre runs the same shape every weekday, and the
 * import reports any period the export defined two different ways instead of picking one silently.
 */
#[ORM\Entity(repositoryClass: TimeSlotRepository::class)]
#[ORM\Table(name: 'time_slot')]
#[ORM\UniqueConstraint(name: 'UNIQ_time_slot_year_index', columns: ['academic_year_id', 'slot_index'])]
class TimeSlot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The course this period belongs to; an import replaces the whole frame of its own course. */
    #[ORM\ManyToOne(targetEntity: AcademicYear::class)]
    #[ORM\JoinColumn(name: 'academic_year_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AcademicYear $academicYear;

    /**
     * The period's ordinal within the day (0-based, the Peñalara {@code indice}) — the same key
     * {@see ScheduleEntry::$slotIndex} and {@see GuardiaCover::$slotIndex} join on.
     */
    #[ORM\Column(name: 'slot_index', type: Types::SMALLINT)]
    private int $slotIndex;

    /** When the period starts. */
    #[ORM\Column(name: 'starts_at', type: Types::TIME_IMMUTABLE)]
    private \DateTimeImmutable $startsAt;

    /** When the period ends. */
    #[ORM\Column(name: 'ends_at', type: Types::TIME_IMMUTABLE)]
    private \DateTimeImmutable $endsAt;

    /** Whether the period is teaching time or a recreo. */
    #[ORM\Column(name: 'kind', length: 16, enumType: TimeSlotKind::class)]
    private TimeSlotKind $kind;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAcademicYear(): AcademicYear
    {
        return $this->academicYear;
    }

    public function setAcademicYear(AcademicYear $academicYear): static
    {
        $this->academicYear = $academicYear;

        return $this;
    }

    public function getSlotIndex(): int
    {
        return $this->slotIndex;
    }

    public function setSlotIndex(int $slotIndex): static
    {
        $this->slotIndex = $slotIndex;

        return $this;
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function setStartsAt(\DateTimeImmutable $startsAt): static
    {
        $this->startsAt = $startsAt;

        return $this;
    }

    public function getEndsAt(): \DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function setEndsAt(\DateTimeImmutable $endsAt): static
    {
        $this->endsAt = $endsAt;

        return $this;
    }

    public function getKind(): TimeSlotKind
    {
        return $this->kind;
    }

    public function setKind(TimeSlotKind $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    /**
     * Whether this period is a recreo — the ones the break duty rota covers.
     *
     * @return bool true for a break period
     */
    public function isBreak(): bool
    {
        return TimeSlotKind::BREAK_TIME === $this->kind;
    }

    /**
     * The period's times as "HH:MM–HH:MM", the way every screen shows a recreo.
     *
     * @return string the time range
     */
    public function timeRange(): string
    {
        return $this->startsAt->format('H:i').'–'.$this->endsAt->format('H:i');
    }
}
