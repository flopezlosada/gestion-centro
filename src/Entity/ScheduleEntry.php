<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ScheduleActivityKind;
use App\Enum\ScheduleEntrySource;
use App\Enum\Weekday;
use App\Repository\ScheduleEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One cell of a teacher's weekly timetable, imported from Peñalara GHC: on a given weekday and time
 * slot the teacher is either teaching a group ({@see ScheduleActivityKind::LECTIVE}) or on a
 * non-teaching duty (a guardia / collaborator slot, with no group).
 *
 * This is mostly imported reference data, replaced on each import (see
 * {@see \App\Command\ImportTimetableCommand}); it is therefore NOT {@see \App\Contract\Auditable} —
 * the audit trail tracks hand edits, not the bulk timetable load. Two reads drive the whole guardias
 * module off this table: "which group/room does teacher T have at weekday W, slot S" (to know what an
 * absence leaves uncovered) and "who is on guardia at weekday W, slot S" (the pool to assign from).
 *
 * A cell records {@see $source}, who wrote it: the export or a person. An import only ever replaces
 * the cells it owns, so hand-marked guardias survive it — see {@see ScheduleEntrySource}.
 *
 * Every cell belongs to one {@see AcademicYear}: timetables change each course, so an import targets a
 * concrete course and replaces only that course's entries, and the parte reads the timetable of the
 * course the queried date falls into. Absences ({@see GuardiaCover}) key off the date alone.
 */
#[ORM\Entity(repositoryClass: ScheduleEntryRepository::class)]
#[ORM\Table(name: 'schedule_entry')]
#[ORM\Index(name: 'IDX_sched_year_slot_kind', columns: ['academic_year_id', 'weekday', 'slot_index', 'kind'])]
#[ORM\Index(name: 'IDX_sched_teacher', columns: ['teacher_id'])]
#[ORM\Index(name: 'IDX_sched_room', columns: ['room_id'])]
class ScheduleEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The course this timetable cell belongs to; an import replaces only its own course's entries. */
    #[ORM\ManyToOne(targetEntity: AcademicYear::class)]
    #[ORM\JoinColumn(name: 'academic_year_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AcademicYear $academicYear;

    /** The teacher this timetable cell belongs to. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'teacher_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $teacher;

    /** The weekday, ISO-8601 (Monday = 1 … Sunday = 7). */
    #[ORM\Column(name: 'weekday', type: Types::SMALLINT, enumType: Weekday::class)]
    private Weekday $weekday;

    /**
     * The slot's ordinal within the day (0-based, the Peñalara {@code indice}): the same index across
     * every weekday denotes the same period (1st hour, break, 2nd hour…). Kept as the join key so the
     * parte and the guardia pool align on the period a user picks in the UI.
     */
    #[ORM\Column(name: 'slot_index', type: Types::SMALLINT)]
    private int $slotIndex;

    /** Slot start time (from the resolved timetable). */
    #[ORM\Column(name: 'starts_at', type: Types::TIME_IMMUTABLE)]
    private \DateTimeImmutable $startsAt;

    /** Slot end time (from the resolved timetable). */
    #[ORM\Column(name: 'ends_at', type: Types::TIME_IMMUTABLE)]
    private \DateTimeImmutable $endsAt;

    /** Whether this cell is teaching or a (guardia / collaborator) duty. */
    #[ORM\Column(name: 'kind', length: 16, enumType: ScheduleActivityKind::class)]
    private ScheduleActivityKind $kind;

    /** Group short name (e.g. "B1A"); null for non-teaching duties. */
    #[ORM\Column(name: 'group_name', length: 64, nullable: true)]
    private ?string $groupName = null;

    /** Room short name (e.g. "A10", "BIBL"); null when the slot has no room. */
    #[ORM\Column(name: 'room_name', length: 64, nullable: true)]
    private ?string $roomName = null;

    /**
     * The catalogued space {@see $roomName} refers to, resolved by {@see \App\Space\RoomSynchroniser}
     * after each import. This is what the space module reads: matching rooms by text would make a
     * stray space or a renamed code drop cells from the occupancy calculation in silence, and the
     * symptom (two groups sent to the same room) would surface far from the cause.
     *
     * Null on the cells that legitimately have no room (guardias, collaborator duty) and, briefly, on
     * imported cells whose card the synchroniser has not created yet.
     */
    #[ORM\ManyToOne(targetEntity: Room::class)]
    #[ORM\JoinColumn(name: 'room_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Room $room = null;

    /** Subject short name (e.g. "Literatura Universal"); null for non-teaching duties. */
    #[ORM\Column(name: 'subject_name', length: 128, nullable: true)]
    private ?string $subjectName = null;

    /**
     * Who wrote this cell: the Peñalara export or a person using the manual guardia editor. Defaults to
     * the export, which is where all but a handful of cells come from; the editor stamps its own.
     */
    #[ORM\Column(name: 'source', length: 16, enumType: ScheduleEntrySource::class, options: ['default' => 'penalara'])]
    private ScheduleEntrySource $source = ScheduleEntrySource::PENALARA;

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

    public function getTeacher(): User
    {
        return $this->teacher;
    }

    public function setTeacher(User $teacher): static
    {
        $this->teacher = $teacher;

        return $this;
    }

    public function getWeekday(): Weekday
    {
        return $this->weekday;
    }

    public function setWeekday(Weekday $weekday): static
    {
        $this->weekday = $weekday;

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

    public function getKind(): ScheduleActivityKind
    {
        return $this->kind;
    }

    public function setKind(ScheduleActivityKind $kind): static
    {
        $this->kind = $kind;

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

    public function getRoom(): ?Room
    {
        return $this->room;
    }

    public function setRoom(?Room $room): static
    {
        $this->room = $room;

        return $this;
    }

    public function getSubjectName(): ?string
    {
        return $this->subjectName;
    }

    public function setSubjectName(?string $subjectName): static
    {
        $this->subjectName = $subjectName;

        return $this;
    }

    public function getSource(): ScheduleEntrySource
    {
        return $this->source;
    }

    public function setSource(ScheduleEntrySource $source): static
    {
        $this->source = $source;

        return $this;
    }
}
