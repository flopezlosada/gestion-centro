<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AssignmentKind;
use App\Repository\SpacePlanAssignmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One line of an alternative: "el martes a 3.ª hora, E1B se va de 2IN5 a 0LC7", or "el martes de 9 a
 * 11, la prueba de la EOI ocupa el salón de actos".
 *
 * The unit everything else is built on: it is what gets printed on the board, what the affected teacher
 * is told, and what the effective timetable reads to know that a room is taken. It belongs to an OPTION,
 * not to the plan — two alternatives say different things about the same moment.
 *
 * {@see $room} is nullable and that is a legitimate state, not an error: "no free room was found" is
 * exactly the line a person has to resolve, and hiding it would be worse than showing it.
 */
#[ORM\Entity(repositoryClass: SpacePlanAssignmentRepository::class)]
#[ORM\Table(name: 'space_plan_assignment')]
#[ORM\Index(name: 'IDX_space_assignment_option', columns: ['option_id', 'date', 'slot_index'])]
#[ORM\Index(name: 'IDX_space_assignment_when', columns: ['date', 'slot_index'])]
class SpacePlanAssignment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SpacePlanOption::class, inversedBy: 'assignments')]
    #[ORM\JoinColumn(name: 'option_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SpacePlanOption $option;

    #[ORM\Column(name: 'date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    /** The period, the same index the timetable and the guardia parte use. */
    #[ORM\Column(name: 'slot_index', type: Types::SMALLINT)]
    private int $slotIndex;

    #[ORM\Column(name: 'kind', length: 16, enumType: AssignmentKind::class)]
    private AssignmentKind $kind = AssignmentKind::RELOCATION;

    /** Where it happens. Null = nowhere found yet, the line a person has to sort out. */
    #[ORM\ManyToOne(targetEntity: Room::class)]
    #[ORM\JoinColumn(name: 'room_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Room $room = null;

    /** Where it was before (relocations only), by name: the origin room may be deleted, the line stays. */
    #[ORM\Column(name: 'origin_room_name', length: 64, nullable: true)]
    private ?string $originRoomName = null;

    /** The group(s), ", "-separated as elsewhere in the application (a period can hold several). */
    #[ORM\Column(name: 'group_names', length: 255, nullable: true)]
    private ?string $groupNames = null;

    /** The subject, for a relocated lesson. */
    #[ORM\Column(name: 'subject_name', length: 128, nullable: true)]
    private ?string $subjectName = null;

    /** What is happening, for an activity ("Prueba EOI"); null for a plain relocation. */
    #[ORM\Column(name: 'activity_title', length: 160, nullable: true)]
    private ?string $activityTitle = null;

    /**
     * The person this line concerns: the teacher of the relocated lesson, or whoever runs the activity.
     * Snapshotted rather than read through {@see $sourceEntry} because that link can be severed (a
     * re-import replaces the cells) and the notice still has to reach somebody.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'teacher_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $teacher = null;

    /** The timetable cell this line displaces, while it exists. */
    #[ORM\ManyToOne(targetEntity: ScheduleEntry::class)]
    #[ORM\JoinColumn(name: 'source_entry_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?ScheduleEntry $sourceEntry = null;

    /**
     * Whether a person has touched this line. Regenerating the alternatives must not quietly undo
     * somebody's decision, and the printed document can mark what was decided rather than proposed.
     */
    #[ORM\Column(name: 'manually_edited', options: ['default' => false])]
    private bool $manuallyEdited = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOption(): SpacePlanOption
    {
        return $this->option;
    }

    public function setOption(SpacePlanOption $option): static
    {
        $this->option = $option;

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

    public function getKind(): AssignmentKind
    {
        return $this->kind;
    }

    public function setKind(AssignmentKind $kind): static
    {
        $this->kind = $kind;

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

    public function getOriginRoomName(): ?string
    {
        return $this->originRoomName;
    }

    public function setOriginRoomName(?string $originRoomName): static
    {
        $this->originRoomName = $originRoomName;

        return $this;
    }

    public function getGroupNames(): ?string
    {
        return $this->groupNames;
    }

    public function setGroupNames(?string $groupNames): static
    {
        $this->groupNames = $groupNames;

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

    public function getActivityTitle(): ?string
    {
        return $this->activityTitle;
    }

    public function setActivityTitle(?string $activityTitle): static
    {
        $this->activityTitle = $activityTitle;

        return $this;
    }

    public function getTeacher(): ?User
    {
        return $this->teacher;
    }

    public function setTeacher(?User $teacher): static
    {
        $this->teacher = $teacher;

        return $this;
    }

    public function getSourceEntry(): ?ScheduleEntry
    {
        return $this->sourceEntry;
    }

    public function setSourceEntry(?ScheduleEntry $sourceEntry): static
    {
        $this->sourceEntry = $sourceEntry;

        return $this;
    }

    public function isManuallyEdited(): bool
    {
        return $this->manuallyEdited;
    }

    public function setManuallyEdited(bool $manuallyEdited): static
    {
        $this->manuallyEdited = $manuallyEdited;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    /**
     * What to call this line on a board or in a notice: the groups for a relocated lesson, the title for
     * an activity.
     *
     * @return string the line's heading
     */
    public function label(): string
    {
        return match ($this->kind) {
            AssignmentKind::RELOCATION => $this->groupNames ?? 'Clase sin grupo',
            AssignmentKind::ACTIVITY => $this->activityTitle ?? 'Actividad',
        };
    }

    /**
     * A stable text form of what this line does, for comparing two alternatives
     * ({@see SpacePlanOption::fingerprint()}).
     *
     * @return string the signature
     */
    public function signature(): string
    {
        return implode('|', [
            $this->date->format('Y-m-d'),
            (string) $this->slotIndex,
            $this->kind->value,
            (string) $this->groupNames,
            (string) $this->activityTitle,
            (string) $this->originRoomName,
            (string) $this->room?->getCode(),
        ]);
    }

    /**
     * Copies this line onto another option — how the fixed lines of the enunciado end up in every
     * alternative without the alternatives having to read the plan.
     *
     * @param SpacePlanOption $option the option to copy it onto
     *
     * @return self the copy, already attached to that option
     */
    public function copyTo(SpacePlanOption $option): self
    {
        $copy = (new self())
            ->setDate($this->date)
            ->setSlotIndex($this->slotIndex)
            ->setKind($this->kind)
            ->setRoom($this->room)
            ->setOriginRoomName($this->originRoomName)
            ->setGroupNames($this->groupNames)
            ->setSubjectName($this->subjectName)
            ->setActivityTitle($this->activityTitle)
            ->setTeacher($this->teacher)
            ->setSourceEntry($this->sourceEntry)
            ->setNote($this->note);
        $option->addAssignment($copy);

        return $copy;
    }
}
