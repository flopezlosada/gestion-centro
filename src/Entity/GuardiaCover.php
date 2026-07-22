<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Repository\GuardiaCoverRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One line of the daily "parte de guardias": on a given date and time slot a teacher is absent, so
 * the group they would have taught needs covering by a guardia teacher.
 *
 * The group/room are snapshotted here from the absent teacher's {@see ScheduleEntry} at creation, so
 * the parte still reads correctly if the timetable is later re-imported. {@see $assignedGuardia} is
 * filled automatically by the equitable engine and may be overridden by the guardia coordinator.
 *
 * An assigned cover counts as done by default — the centre's rule is "the less they have to touch,
 * the better". The only human gesture is flagging an incident after the fact ({@see $notCovered}):
 * the guardia teacher did not show, or the absent teacher turned up after all. The per-teacher,
 * per-slot "hours done" counter the engine balances on is NOT stored — it is derived by counting
 * assigned covers with no incident (see {@see GuardiaCoverRepository::loadBySlot()}), so it can never
 * drift out of sync.
 *
 * Change tracking is automatic: the entity is {@see Auditable}.
 */
#[ORM\Entity(repositoryClass: GuardiaCoverRepository::class)]
#[ORM\Table(name: 'guardia_cover')]
#[ORM\Index(name: 'IDX_cover_date_slot', columns: ['cover_date', 'slot_index'])]
#[ORM\Index(name: 'IDX_cover_assigned', columns: ['assigned_guardia_id'])]
#[ORM\UniqueConstraint(name: 'UNIQ_cover_absence', columns: ['absent_teacher_id', 'cover_date', 'slot_index'])]
class GuardiaCover implements Auditable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The day of the absence. */
    #[ORM\Column(name: 'cover_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    /** The slot within the day (0-based Peñalara {@code indice}), matching {@see ScheduleEntry::$slotIndex}. */
    #[ORM\Column(name: 'slot_index', type: Types::SMALLINT)]
    private int $slotIndex;

    /** The teacher who is absent this slot. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'absent_teacher_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $absentTeacher;

    /** Group left uncovered (short name), snapshotted from the absent teacher's timetable. */
    #[ORM\Column(name: 'group_name', length: 64, nullable: true)]
    private ?string $groupName = null;

    /** Room of the uncovered group (short name), snapshotted from the timetable. */
    #[ORM\Column(name: 'room_name', length: 64, nullable: true)]
    private ?string $roomName = null;

    /** Free-text task the absent teacher left for the group (shown behind the envelope icon). */
    #[ORM\Column(name: 'task_note', type: Types::TEXT, nullable: true)]
    private ?string $taskNote = null;

    /**
     * The guardia teacher assigned to cover. Set automatically by the equitable engine and editable
     * by the coordinator. Nullable while nobody is available (the parte shows the gap) or before the
     * assignment runs; cleared with {@code ON DELETE SET NULL} if that user is removed.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'assigned_guardia_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $assignedGuardia = null;

    /**
     * Incident flag: the cover did not actually happen (the guardia teacher did not show, or the
     * absent teacher turned up). False by default — an assigned cover is assumed done — and only a
     * cover WITHOUT an incident counts towards the equitable balance.
     */
    #[ORM\Column(name: 'not_covered', type: Types::BOOLEAN)]
    private bool $notCovered = false;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getSlotIndex(): int
    {
        return $this->slotIndex;
    }

    public function setSlotIndex(int $slotIndex): static
    {
        $this->slotIndex = $slotIndex;

        return $this;
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

    public function getGroupName(): ?string
    {
        return $this->groupName;
    }

    public function setGroupName(?string $groupName): static
    {
        $this->groupName = $groupName;

        return $this;
    }

    public function getRoomName(): ?string
    {
        return $this->roomName;
    }

    public function setRoomName(?string $roomName): static
    {
        $this->roomName = $roomName;

        return $this;
    }

    public function getTaskNote(): ?string
    {
        return $this->taskNote;
    }

    public function setTaskNote(?string $taskNote): static
    {
        $this->taskNote = null !== $taskNote && '' !== trim($taskNote) ? trim($taskNote) : null;

        return $this;
    }

    public function getAssignedGuardia(): ?User
    {
        return $this->assignedGuardia;
    }

    public function setAssignedGuardia(?User $assignedGuardia): static
    {
        $this->assignedGuardia = $assignedGuardia;

        return $this;
    }

    public function isNotCovered(): bool
    {
        return $this->notCovered;
    }

    public function setNotCovered(bool $notCovered): static
    {
        $this->notCovered = $notCovered;

        return $this;
    }
}
