<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Repository\BreakDutyGapRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A day on which a break duty was not going to be done, because the teacher on the rota is away.
 *
 * The centre's rule is explicit: a recreo is NOT re-covered — there is nobody spare — so instead of
 * reassigning it, the equipo directivo gets an alert and looks for a volunteer. This row is that event:
 * it exists so the alert fires exactly once per (duty, day) however many times the absence is edited
 * (registering the morning first and the whole day afterwards is routine), so the centre can later say
 * how many recreos went unwatched, and so a colleague who steps in is on record.
 *
 * A gap does not touch the equitable counter. The rota is fixed and the counter measures the load it
 * hands each teacher for the year, not attendance day by day; who stepped in and who was away is read
 * from these rows, where it means what it says.
 *
 * One row per (duty, day) — the unique key is what makes the "alert once" promise keepable.
 *
 * Change tracking is automatic: the entity is {@see Auditable}.
 */
#[ORM\Entity(repositoryClass: BreakDutyGapRepository::class)]
#[ORM\Table(name: 'break_duty_gap')]
#[ORM\Index(name: 'IDX_break_gap_date', columns: ['gap_date'])]
#[ORM\UniqueConstraint(name: 'UNIQ_break_gap_duty_date', columns: ['assignment_id', 'gap_date'])]
class BreakDutyGap implements Auditable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The rota line left unattended. Deleting the duty takes its gaps with it. */
    #[ORM\ManyToOne(targetEntity: BreakDutyAssignment::class)]
    #[ORM\JoinColumn(name: 'assignment_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private BreakDutyAssignment $assignment;

    /** The day the duty went unattended. */
    #[ORM\Column(name: 'gap_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    /**
     * The colleague who volunteered to watch the zone instead, once somebody does. Null while the recreo
     * is still uncovered — which is the normal state of a fresh gap, not an anomaly.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'volunteer_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $volunteer = null;

    /** Free-text note the equipo directivo may leave ("lo cubre el conserje", "sin patio ese día"). */
    #[ORM\Column(name: 'note', type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAssignment(): BreakDutyAssignment
    {
        return $this->assignment;
    }

    public function setAssignment(BreakDutyAssignment $assignment): static
    {
        $this->assignment = $assignment;

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

    public function getVolunteer(): ?User
    {
        return $this->volunteer;
    }

    public function setVolunteer(?User $volunteer): static
    {
        $this->volunteer = $volunteer;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = null !== $note && '' !== trim($note) ? trim($note) : null;

        return $this;
    }

    /**
     * Whether somebody ended up watching the zone after all.
     *
     * @return bool true when a volunteer stepped in
     */
    public function isCovered(): bool
    {
        return null !== $this->volunteer;
    }
}
