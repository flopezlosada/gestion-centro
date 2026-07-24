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
 * Every cover belongs to an {@see Absence} (the teacher being away that day); the group/room, the
 * absent teacher and the date are snapshotted here from the absent teacher's {@see ScheduleEntry} at
 * creation, so the parte still reads correctly if the timetable is later re-imported and every
 * analytics query keeps reading per period without a join. {@see $assignedGuardia} is filled
 * automatically by the equitable engine and may be overridden by the guardia coordinator.
 *
 * The task the absent teacher leaves for the group lives in two operational fields the covering
 * guardia can see: an uploaded document ({@see $taskDocumentPath}) and/or a free-text description
 * ({@see $taskDescription}). Both are per class, because each group gets its own work. Why the
 * teacher is away is NOT here — that is the private {@see Absence::$reason}, off-limits to the
 * covering guardia.
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

    /** The absence this cover belongs to (the teacher being away that day); holds the private reason. */
    #[ORM\ManyToOne(targetEntity: Absence::class)]
    #[ORM\JoinColumn(name: 'absence_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Absence $absence;

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

    /**
     * Group(s) left uncovered, snapshotted from the absent teacher's timetable. Usually one short
     * name; for a multi-group activity it holds the ", "-joined list of every group in the period,
     * hence the wider column.
     */
    #[ORM\Column(name: 'group_name', length: 255, nullable: true)]
    private ?string $groupName = null;

    /** Room of the uncovered group (short name), snapshotted from the timetable. */
    #[ORM\Column(name: 'room_name', length: 64, nullable: true)]
    private ?string $roomName = null;

    /**
     * Storage-relative path of the task document the absent teacher uploaded for this group (a PDF,
     * a scan…), as returned by {@see \App\Service\FileUploader}. Nullable — a class may carry only a
     * description, or nothing.
     */
    #[ORM\Column(name: 'task_document_path', length: 255, nullable: true)]
    private ?string $taskDocumentPath = null;

    /** The original client filename of the task document, kept so the download is served with a name. */
    #[ORM\Column(name: 'task_document_name', length: 255, nullable: true)]
    private ?string $taskDocumentName = null;

    /**
     * Free-text explanation for the group that does not come in the document ("el examen está en
     * conserjería", "solo la primera media hora"). The covering guardia can read it. Nullable.
     */
    #[ORM\Column(name: 'task_description', type: Types::TEXT, nullable: true)]
    private ?string $taskDescription = null;

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

    public function getAbsence(): Absence
    {
        return $this->absence;
    }

    public function setAbsence(Absence $absence): static
    {
        $this->absence = $absence;

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

    public function getTaskDocumentPath(): ?string
    {
        return $this->taskDocumentPath;
    }

    public function setTaskDocumentPath(?string $taskDocumentPath): static
    {
        $this->taskDocumentPath = $taskDocumentPath;

        return $this;
    }

    public function getTaskDocumentName(): ?string
    {
        return $this->taskDocumentName;
    }

    public function setTaskDocumentName(?string $taskDocumentName): static
    {
        $this->taskDocumentName = $taskDocumentName;

        return $this;
    }

    public function getTaskDescription(): ?string
    {
        return $this->taskDescription;
    }

    public function setTaskDescription(?string $taskDescription): static
    {
        $this->taskDescription = null !== $taskDescription && '' !== trim($taskDescription) ? trim($taskDescription) : null;

        return $this;
    }

    /**
     * Whether this cover carries anything for the covering guardia to work with — a task document, a
     * description, or both. Drives the "tiene tarea / sin tarea" cues across the guardia screens.
     *
     * @return bool true if there is a document or a description
     */
    public function hasTask(): bool
    {
        return null !== $this->taskDocumentPath || null !== $this->taskDescription;
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
