<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ProposalStrategy;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One whole alternative for a {@see SpacePlan}: "Opción A — mueve lo mínimo", with every line it
 * implies.
 *
 * Self-contained on purpose. The lines that are fixed by the event (an external exam in the assembly
 * hall) are copied into EVERY option rather than being read from the plan and merged: it costs a few
 * dozen duplicated rows and it means the printed document and the effective timetable read one place
 * instead of stitching enunciado and variation together.
 *
 * {@see $metrics} is not decoration. Three options with no numbers beside them are noise, and whoever
 * decides will just take the first one; the metrics are what make them comparable.
 */
#[ORM\Entity]
#[ORM\Table(name: 'space_plan_option')]
class SpacePlanOption
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SpacePlan::class, inversedBy: 'options')]
    #[ORM\JoinColumn(name: 'plan_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SpacePlan $plan;

    /** "Opción A", "Opción B"… what the person calls it while comparing. */
    #[ORM\Column(length: 32)]
    private string $label;

    /** The criterion it was built with. */
    #[ORM\Column(name: 'strategy', length: 32, enumType: ProposalStrategy::class)]
    private ProposalStrategy $strategy = ProposalStrategy::MANUAL;

    /** One sentence a person can act on: "Mueve 6 clases; usa el laboratorio de Física dos horas". */
    #[ORM\Column(length: 255)]
    private string $rationale = '';

    /**
     * The figures that make options comparable: movedClasses, affectedGroups, affectedTeachers,
     * specialisedRoomsUsed, unresolved.
     *
     * @var array<string, int>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $metrics = [];

    #[ORM\Column(name: 'generated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $generatedAt;

    /**
     * The lines: what happens where, day by day and period by period.
     *
     * @var Collection<int, SpacePlanAssignment>
     */
    #[ORM\OneToMany(targetEntity: SpacePlanAssignment::class, mappedBy: 'option', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['date' => 'ASC', 'slotIndex' => 'ASC'])]
    private Collection $assignments;

    public function __construct()
    {
        $this->assignments = new ArrayCollection();
        $this->generatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlan(): SpacePlan
    {
        return $this->plan;
    }

    public function setPlan(SpacePlan $plan): static
    {
        $this->plan = $plan;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getStrategy(): ProposalStrategy
    {
        return $this->strategy;
    }

    public function setStrategy(ProposalStrategy $strategy): static
    {
        $this->strategy = $strategy;

        return $this;
    }

    public function getRationale(): string
    {
        return $this->rationale;
    }

    /**
     * Sets the sentence, capped to the column width. Capped here rather than trusted to fit: the text
     * grows when two criteria agree ("«X» propone exactamente lo mismo"), and a 255-char overflow would
     * surface as a database error in the middle of generating proposals.
     *
     * @param string $rationale the sentence
     */
    public function setRationale(string $rationale): static
    {
        $this->rationale = mb_substr($rationale, 0, 255);

        return $this;
    }

    /**
     * @return array<string, int> the comparison figures
     */
    public function getMetrics(): array
    {
        return $this->metrics;
    }

    /**
     * @param array<string, int> $metrics the comparison figures
     */
    public function setMetrics(array $metrics): static
    {
        $this->metrics = $metrics;

        return $this;
    }

    public function getGeneratedAt(): \DateTimeImmutable
    {
        return $this->generatedAt;
    }

    public function setGeneratedAt(\DateTimeImmutable $generatedAt): static
    {
        $this->generatedAt = $generatedAt;

        return $this;
    }

    /**
     * @return Collection<int, SpacePlanAssignment> the lines
     */
    public function getAssignments(): Collection
    {
        return $this->assignments;
    }

    public function addAssignment(SpacePlanAssignment $assignment): static
    {
        if (!$this->assignments->contains($assignment)) {
            $this->assignments->add($assignment);
            $assignment->setOption($this);
        }

        return $this;
    }

    public function removeAssignment(SpacePlanAssignment $assignment): static
    {
        $this->assignments->removeElement($assignment);

        return $this;
    }

    /**
     * One figure, or zero if the option does not carry it.
     *
     * @param string $name the metric name
     *
     * @return int its value
     */
    public function metric(string $name): int
    {
        return $this->metrics[$name] ?? 0;
    }

    /**
     * Whether the option leaves a line with nowhere to go. That is not a failure of the engine — with
     * every room taken there IS nowhere — but it is the first thing whoever decides has to see.
     *
     * @return bool true when some line has no room
     */
    public function hasUnresolved(): bool
    {
        return $this->metric('unresolved') > 0;
    }

    /**
     * Whether a person has changed any of its lines. Shown next to the criterion ("retocada a mano") and
     * what keeps a regeneration from undoing somebody's decision.
     *
     * @return bool true when at least one line was edited by hand
     */
    public function isEdited(): bool
    {
        foreach ($this->assignments as $assignment) {
            if ($assignment->isManuallyEdited()) {
                return true;
            }
        }

        return false;
    }

    /**
     * A stable fingerprint of what the option actually does: the set of its lines, each as
     * "date|slot|group|origin>destination". Two strategies that end up proposing the same thing produce
     * the same fingerprint, which is how the interface can say "B y C son equivalentes" instead of
     * showing three identical cards.
     *
     * @return string the fingerprint
     */
    public function fingerprint(): string
    {
        $lines = [];
        foreach ($this->assignments as $assignment) {
            $lines[] = $assignment->signature();
        }
        sort($lines);

        return md5(implode('|', $lines));
    }
}
