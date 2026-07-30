<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EducationLevel;
use App\Repository\GuardiaTaskBankItemRepository;
use App\Util\GroupCode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One ready-made piece of work in the guardia task bank: something a department leaves prepared for a
 * given level, so that when a teacher is away WITHOUT having uploaded anything the covering guardia
 * can hand the group a real task instead of improvising.
 *
 * The bank is filled by the departments ({@see $department} is who answers for it) and read by whoever
 * is covering. What narrows it down is {@see $level} + {@see $subject}: the centre's rule is that a
 * group works on the subject it was going to have, so both are required and the subject is spelled as
 * the timetable spells it ({@see \App\Repository\ScheduleEntryRepository::distinctSubjects()}) — two
 * departments typing "Lengua" and "Lengua Castellana" by hand would never match a class.
 * {@see $sections} narrows it further, and is optional because an optional subject mixes pupils from
 * several sections or from none.
 *
 * Each task belongs to a course ({@see $academicYear}): the centre empties the bank every September,
 * which here means last year's tasks stop being offered while the guardias that used them keep showing
 * what the group was given.
 *
 * The work itself lives in the same two shapes as a {@see GuardiaCover}'s task: an uploaded document
 * and/or a free-text description. {@see $suggestedCopies} travels with it because a task that reaches
 * the copy room without a number of copies is useless.
 *
 * Validation lives in {@see \App\Form\GuardiaTaskBankFormData}, the only way in: constraints repeated
 * here would be dead code free to drift from the ones that actually run.
 *
 * Retired items keep their history: {@see $active} is a soft delete, so a cover that used one still
 * shows what the group was given. {@see $timesUsed} is a plain counter, both to let a department see
 * what actually gets used and to keep the random pick from landing on the same sheet forever.
 */
#[ORM\Entity(repositoryClass: GuardiaTaskBankItemRepository::class)]
#[ORM\Table(name: 'guardia_task_bank')]
// El banco se lee siempre acotado a curso + nivel (y materia) y solo entre las activas; este índice
// cubre además la FK del curso por prefijo, así que no hace falta uno suyo aparte.
#[ORM\Index(name: 'IDX_bank_year_level_active', columns: ['academic_year_id', 'level', 'active'])]
// Los índices de las claves ajenas van nombrados a mano (como en guardia_cover) para que el esquema y
// el mapping coincidan y schema:validate no arrastre un renombrado pendiente.
#[ORM\Index(name: 'IDX_bank_department', columns: ['department_id'])]
#[ORM\Index(name: 'IDX_bank_created_by', columns: ['created_by_id'])]
class GuardiaTaskBankItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * The department that contributes and answers for this task. Not nullable: "las tareas las meten
     * los departamentos" is the whole point, and it is who the coordinator asks when something is off.
     * The form never touches a half-built entity — it works on {@see \App\Form\GuardiaTaskBankFormData}.
     */
    #[ORM\ManyToOne(targetEntity: Department::class)]
    #[ORM\JoinColumn(name: 'department_id', referencedColumnName: 'id', nullable: false)]
    private Department $department;

    /** The teaching level the task is aimed at. */
    #[ORM\Column(name: 'level', length: 16, enumType: EducationLevel::class)]
    private EducationLevel $level;

    /** The course this task belongs to; the bank offers only the current one. */
    #[ORM\ManyToOne(targetEntity: AcademicYear::class)]
    #[ORM\JoinColumn(name: 'academic_year_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AcademicYear $academicYear;

    /** The subject the task is for, as the timetable names it. Required: it has to match the class. */
    #[ORM\Column(name: 'subject', length: 128)]
    private string $subject = '';

    /**
     * The section letters the task is restricted to ("A", "A,C"), or empty for the whole level+subject.
     * Stored as the canonical comma-separated list {@see \App\Util\GroupCode::parseSections()} builds,
     * and matched against the letters of the group being covered.
     */
    #[ORM\Column(name: 'sections', length: 64, nullable: true)]
    private ?string $sections = null;

    /** Short name of the task, what the covering teacher reads in the list. */
    #[ORM\Column(length: 160)]
    private string $title = '';

    /** What the group has to do, when it does not all come in the document. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Storage-relative path of the attached document, as returned by {@see \App\Service\FileUploader}. */
    #[ORM\Column(name: 'document_path', length: 255, nullable: true)]
    private ?string $documentPath = null;

    /** Original client filename of the document, so the download is served with a name. */
    #[ORM\Column(name: 'document_name', length: 255, nullable: true)]
    private ?string $documentName = null;

    /**
     * How many copies this task usually needs, prefilled into the copy-room order. Null when the
     * department did not say — the person ordering then has to type a number, which is still required.
     */
    #[ORM\Column(name: 'suggested_copies', type: Types::SMALLINT, nullable: true)]
    private ?int $suggestedCopies = null;

    /** Retired items are kept for history but no longer offered when picking a task. */
    #[ORM\Column]
    private bool $active = true;

    /** How many times this task has been handed to a group. */
    #[ORM\Column(name: 'times_used', options: ['default' => 0])]
    private int $timesUsed = 0;

    /** Who added it, for the department to know who to ask. Cleared if that user is removed. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDepartment(): Department
    {
        return $this->department;
    }

    public function setDepartment(Department $department): static
    {
        $this->department = $department;

        return $this;
    }

    public function getLevel(): EducationLevel
    {
        return $this->level;
    }

    public function setLevel(EducationLevel $level): static
    {
        $this->level = $level;

        return $this;
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

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): static
    {
        $this->subject = trim($subject);

        return $this;
    }

    /**
     * The section letters this task is restricted to, or an empty list when it fits the whole
     * level + subject.
     *
     * @return list<string> the letters
     */
    public function getSections(): array
    {
        return GroupCode::parseSections($this->sections);
    }

    /**
     * Restricts the task to some section letters (empty or null lifts the restriction).
     *
     * @param list<string>|string|null $sections the letters, as a list or as typed text
     */
    public function setSections(array|string|null $sections): static
    {
        $letters = \is_array($sections) ? $sections : GroupCode::parseSections($sections);
        $letters = GroupCode::parseSections(implode(',', $letters));
        $this->sections = [] === $letters ? null : implode(',', $letters);

        return $this;
    }

    /**
     * The letters as typed back into the form field.
     *
     * @return string|null the canonical comma-separated letters, or null
     */
    public function getSectionsText(): ?string
    {
        return $this->sections;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = trim($title);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = null !== $description && '' !== trim($description) ? trim($description) : null;

        return $this;
    }

    public function getDocumentPath(): ?string
    {
        return $this->documentPath;
    }

    public function setDocumentPath(?string $documentPath): static
    {
        $this->documentPath = $documentPath;

        return $this;
    }

    public function getDocumentName(): ?string
    {
        return $this->documentName;
    }

    public function setDocumentName(?string $documentName): static
    {
        $this->documentName = $documentName;

        return $this;
    }

    /**
     * Whether the task carries an attached document (the thing the copy room would print).
     *
     * @return bool true when there is a document
     */
    public function hasDocument(): bool
    {
        return null !== $this->documentPath;
    }

    public function getSuggestedCopies(): ?int
    {
        return $this->suggestedCopies;
    }

    public function setSuggestedCopies(?int $suggestedCopies): static
    {
        $this->suggestedCopies = $suggestedCopies;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getTimesUsed(): int
    {
        return $this->timesUsed;
    }

    /**
     * Records that this task has just been handed to a group.
     */
    public function recordUse(): static
    {
        ++$this->timesUsed;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
