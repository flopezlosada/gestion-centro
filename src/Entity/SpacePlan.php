<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Enum\SpacePlanKind;
use App\Enum\SpacePlanStatus;
use App\Enum\SubstitutionScope;
use App\Repository\SpacePlanRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * One alteration of where classes happen: the workshop that takes three rooms next Tuesday, the week 2º
 * de Bachillerato sits its exams in the English rooms, the cultural days.
 *
 * The centre described three needs and this is ONE entity, because all three are the same sequence:
 * state what the event occupies → the engine proposes several alternatives → the equipo directivo
 * compares, edits and approves one → the affected people are told → a printable document goes up on the
 * boards. What differs is only {@see $substitutionScope} (whose ordinary timetable stops applying) and
 * which proposer builds the alternatives.
 *
 * The pieces around it: {@see SpacePlanActivity} is the enunciado (what the event brings in and where),
 * {@see SpacePlanOption} is one whole alternative, {@see SpacePlanAssignment} is a line of it.
 *
 * THE RULE, without exceptions: only a plan in {@see SpacePlanStatus::APPROVED} alters the effective
 * timetable. A draft changes nothing anybody else can see.
 *
 * {@see Auditable}: who approved a plan, and when, ends up printed on a document for the boards.
 */
#[ORM\Entity(repositoryClass: SpacePlanRepository::class)]
#[ORM\Table(name: 'space_plan')]
#[ORM\Index(name: 'IDX_space_plan_dates', columns: ['academic_year_id', 'date_from', 'date_to'])]
#[ORM\Index(name: 'IDX_space_plan_status', columns: ['status'])]
#[Assert\Callback('validateDatesAndScope')]
class SpacePlan implements Auditable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The course the plan belongs to; its timetable is the one the proposals are built against. */
    #[ORM\ManyToOne(targetEntity: AcademicYear::class)]
    #[ORM\JoinColumn(name: 'academic_year_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AcademicYear $academicYear;

    /** Which of the centre's three cases this is. A label and a form preset — never a branch in logic. */
    #[ORM\Column(name: 'kind', length: 24, enumType: SpacePlanKind::class)]
    private SpacePlanKind $kind = SpacePlanKind::ROOM_CHANGE;

    /** What it is, in the words that will head the published document. */
    #[ORM\Column(length: 160)]
    #[Assert\NotBlank(message: 'Ponle un título al plan (por ejemplo, "Talleres de Cruz Roja, 3-5 de marzo").')]
    #[Assert\Length(max: 160)]
    private string $title;

    /**
     * The reason as the centre wants it told: it goes into the notice each affected teacher receives and
     * onto the printed document. Separate from {@see $internalNotes} because "por motivos organizativos"
     * is what the corridor needs to read, and the rest is nobody else's business.
     */
    #[ORM\Column(name: 'public_reason', length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $publicReason = null;

    /** Anything that must not be published. */
    #[ORM\Column(name: 'internal_notes', type: Types::TEXT, nullable: true)]
    private ?string $internalNotes = null;

    /** First day the plan applies to. */
    #[ORM\Column(name: 'date_from', type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'Indica el primer día.')]
    private \DateTimeImmutable $dateFrom;

    /** Last day it applies to; the same as {@see $dateFrom} for a one-day plan. */
    #[ORM\Column(name: 'date_to', type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'Indica el último día.')]
    private \DateTimeImmutable $dateTo;

    /** First period affected, or null for the whole day. */
    #[ORM\Column(name: 'slot_from', type: Types::SMALLINT, nullable: true)]
    private ?int $slotFrom = null;

    /** Last period affected, or null for the whole day. */
    #[ORM\Column(name: 'slot_to', type: Types::SMALLINT, nullable: true)]
    private ?int $slotTo = null;

    /** Whose ordinary timetable stops applying. The field that makes one mechanism cover three cases. */
    #[ORM\Column(name: 'substitution_scope', length: 16, enumType: SubstitutionScope::class, options: ['default' => 'none'])]
    private SubstitutionScope $substitutionScope = SubstitutionScope::NONE;

    /**
     * The groups whose ordinary lessons the plan replaces, when the scope is
     * {@see SubstitutionScope::GROUPS}. Group names, as the timetable spells them — the application has
     * no group entity: groups live as names on the timetable cells.
     *
     * @var list<string>
     */
    #[ORM\Column(name: 'scope_group_names', type: Types::JSON)]
    private array $scopeGroupNames = [];

    /** Where the plan is in its life. */
    #[ORM\Column(name: 'status', length: 16, enumType: SpacePlanStatus::class, options: ['default' => 'draft'])]
    private SpacePlanStatus $status = SpacePlanStatus::DRAFT;

    /**
     * The alternatives generated for this plan.
     *
     * @var Collection<int, SpacePlanOption>
     */
    #[ORM\OneToMany(targetEntity: SpacePlanOption::class, mappedBy: 'plan', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['label' => 'ASC'])]
    private Collection $options;

    /**
     * What the event brings in: the enunciado the proposals are built from.
     *
     * @var Collection<int, SpacePlanActivity>
     */
    #[ORM\OneToMany(targetEntity: SpacePlanActivity::class, mappedBy: 'plan', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $activities;

    /** The alternative that was chosen; the only one that means anything once approved. */
    #[ORM\ManyToOne(targetEntity: SpacePlanOption::class)]
    #[ORM\JoinColumn(name: 'chosen_option_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?SpacePlanOption $chosenOption = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $createdBy;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'approved_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $approvedBy = null;

    #[ORM\Column(name: 'approved_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    /**
     * The most sessions one person may be given in this plan, or null for no cap.
     *
     * The centre asked to "especificar cuántas sesiones cubre cada profe" ("dos sesiones y dos guardias,
     * o cuatro guardias y una sesión"). This is one number for everybody rather than a quota per person:
     * a screen with a box for each of eighty teachers is a screen nobody fills in, and the rota shares
     * the load evenly anyway. Anything that needs to be different is changed line by line, which is
     * where the real exceptions live.
     */
    #[ORM\Column(name: 'staff_quota', type: Types::SMALLINT, nullable: true)]
    #[Assert\Positive(message: 'El máximo de sesiones por persona debe ser mayor que cero.')]
    private ?int $staffQuota = null;

    /** When the affected people were told. Null means nobody knows yet. */
    #[ORM\Column(name: 'notified_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $notifiedAt = null;

    public function __construct()
    {
        $this->options = new ArrayCollection();
        $this->activities = new ArrayCollection();
    }

    /**
     * Validates the plan as a whole: the dates must run forwards, the periods too, and a plan that says
     * "only these groups" must name at least one. Enforced as a class constraint because each field is
     * fine on its own and only the combination can be nonsense.
     *
     * @param ExecutionContextInterface $context the validation context
     */
    public function validateDatesAndScope(ExecutionContextInterface $context): void
    {
        if (isset($this->dateFrom, $this->dateTo) && $this->dateFrom > $this->dateTo) {
            $context->buildViolation('El último día no puede ser anterior al primero.')
                ->atPath('dateTo')
                ->addViolation();
        }

        if (null !== $this->slotFrom && null !== $this->slotTo && $this->slotFrom > $this->slotTo) {
            $context->buildViolation('La última hora no puede ser anterior a la primera.')
                ->atPath('slotTo')
                ->addViolation();
        }

        if (SubstitutionScope::GROUPS === $this->substitutionScope && [] === $this->scopeGroupNames) {
            $context->buildViolation('Has dicho que se sustituye el horario de algunos grupos: indica cuáles.')
                ->atPath('scopeGroupNames')
                ->addViolation();
        }
    }

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

    public function getKind(): SpacePlanKind
    {
        return $this->kind;
    }

    public function setKind(SpacePlanKind $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getPublicReason(): ?string
    {
        return $this->publicReason;
    }

    public function setPublicReason(?string $publicReason): static
    {
        $this->publicReason = $publicReason;

        return $this;
    }

    public function getInternalNotes(): ?string
    {
        return $this->internalNotes;
    }

    public function setInternalNotes(?string $internalNotes): static
    {
        $this->internalNotes = $internalNotes;

        return $this;
    }

    public function getDateFrom(): \DateTimeImmutable
    {
        return $this->dateFrom;
    }

    public function setDateFrom(\DateTimeImmutable $dateFrom): static
    {
        $this->dateFrom = $dateFrom;

        return $this;
    }

    public function getDateTo(): \DateTimeImmutable
    {
        return $this->dateTo;
    }

    public function setDateTo(\DateTimeImmutable $dateTo): static
    {
        $this->dateTo = $dateTo;

        return $this;
    }

    public function getSlotFrom(): ?int
    {
        return $this->slotFrom;
    }

    public function setSlotFrom(?int $slotFrom): static
    {
        $this->slotFrom = $slotFrom;

        return $this;
    }

    public function getSlotTo(): ?int
    {
        return $this->slotTo;
    }

    public function setSlotTo(?int $slotTo): static
    {
        $this->slotTo = $slotTo;

        return $this;
    }

    public function getSubstitutionScope(): SubstitutionScope
    {
        return $this->substitutionScope;
    }

    public function setSubstitutionScope(SubstitutionScope $substitutionScope): static
    {
        $this->substitutionScope = $substitutionScope;

        return $this;
    }

    /**
     * @return list<string> the groups whose ordinary lessons the plan replaces
     */
    public function getScopeGroupNames(): array
    {
        return $this->scopeGroupNames;
    }

    /**
     * @param list<string> $scopeGroupNames the group names
     */
    public function setScopeGroupNames(array $scopeGroupNames): static
    {
        $this->scopeGroupNames = array_values(array_unique(array_filter(
            array_map(static fn (string $g): string => trim($g), $scopeGroupNames),
            static fn (string $g): bool => '' !== $g,
        )));

        return $this;
    }

    public function getStatus(): SpacePlanStatus
    {
        return $this->status;
    }

    public function setStatus(SpacePlanStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    /**
     * @return Collection<int, SpacePlanOption> the alternatives
     */
    public function getOptions(): Collection
    {
        return $this->options;
    }

    public function addOption(SpacePlanOption $option): static
    {
        if (!$this->options->contains($option)) {
            $this->options->add($option);
            $option->setPlan($this);
        }

        return $this;
    }

    public function removeOption(SpacePlanOption $option): static
    {
        $this->options->removeElement($option);

        return $this;
    }

    /**
     * @return Collection<int, SpacePlanActivity> what the event brings in
     */
    public function getActivities(): Collection
    {
        return $this->activities;
    }

    public function addActivity(SpacePlanActivity $activity): static
    {
        if (!$this->activities->contains($activity)) {
            $this->activities->add($activity);
            $activity->setPlan($this);
        }

        return $this;
    }

    public function removeActivity(SpacePlanActivity $activity): static
    {
        $this->activities->removeElement($activity);

        return $this;
    }

    public function getChosenOption(): ?SpacePlanOption
    {
        return $this->chosenOption;
    }

    public function setChosenOption(?SpacePlanOption $chosenOption): static
    {
        $this->chosenOption = $chosenOption;

        return $this;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getApprovedBy(): ?User
    {
        return $this->approvedBy;
    }

    public function setApprovedBy(?User $approvedBy): static
    {
        $this->approvedBy = $approvedBy;

        return $this;
    }

    public function getApprovedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function setApprovedAt(?\DateTimeImmutable $approvedAt): static
    {
        $this->approvedAt = $approvedAt;

        return $this;
    }

    public function getStaffQuota(): ?int
    {
        return $this->staffQuota;
    }

    public function setStaffQuota(?int $staffQuota): static
    {
        $this->staffQuota = $staffQuota;

        return $this;
    }

    public function getNotifiedAt(): ?\DateTimeImmutable
    {
        return $this->notifiedAt;
    }

    public function setNotifiedAt(?\DateTimeImmutable $notifiedAt): static
    {
        $this->notifiedAt = $notifiedAt;

        return $this;
    }

    /**
     * Every date the plan covers, first to last. Weekends and holidays are NOT filtered here — that
     * needs the school calendar, which is a service; the proposer skips them.
     *
     * @return list<\DateTimeImmutable> the dates, earliest first
     */
    public function dates(): array
    {
        $dates = [];
        for ($date = $this->dateFrom; $date <= $this->dateTo; $date = $date->modify('+1 day')) {
            $dates[] = $date;
        }

        return $dates;
    }

    /**
     * Whether the plan covers a given date and period. Used by the effective timetable to decide whether
     * a plan has anything to say about a moment before looking at its lines.
     *
     * @param \DateTimeImmutable $date      the day
     * @param int                $slotIndex the period index
     *
     * @return bool true when the moment falls inside the plan's window
     */
    public function covers(\DateTimeImmutable $date, int $slotIndex): bool
    {
        $day = $date->setTime(0, 0);
        if ($day < $this->dateFrom->setTime(0, 0) || $day > $this->dateTo->setTime(0, 0)) {
            return false;
        }

        return (null === $this->slotFrom || $slotIndex >= $this->slotFrom)
            && (null === $this->slotTo || $slotIndex <= $this->slotTo);
    }

    /**
     * Whether the given group's ordinary lessons are replaced while the plan is in force.
     *
     * @param string|null $groupName the group, or null for a cell with no group
     *
     * @return bool true when that group has no ordinary lesson under this plan
     */
    public function replacesTimetableFor(?string $groupName): bool
    {
        return $this->substitutionScope->replaces((string) $groupName, $this->scopeGroupNames);
    }
}
