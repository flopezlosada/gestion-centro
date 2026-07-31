<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Enum\IncidentPriority;
use App\Enum\IncidentStatus;
use App\Repository\TicIncidentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A TIC fault someone ran into: the projector that will not turn on, the computer room half down, a
 * laptop that does not charge. The centre keeps these in the Aula Virtual today and asked for a register
 * of their own, with the priority, the room, the group that was in it, the time and who reports it.
 *
 * The point of asking for the GROUP is being able to reconstruct what happened, so it only applies to
 * equipment a class was using: {@see $individualUse} marks the other case — a personal laptop, the
 * machine in a department office — and then there is no group to name and the field is not even asked
 * for. The two are kept mutually exclusive by {@see markIndividualUse()} rather than by a note in a
 * javadoc: "de uso individual y además del grupo 2ºB" should not be representable.
 */
#[ORM\Entity(repositoryClass: TicIncidentRepository::class)]
#[ORM\Table(name: 'tic_incident')]
// Serves the default list: what is still open, most urgent and most recent first.
#[ORM\Index(name: 'idx_tic_incident_open', columns: ['status', 'priority_weight', 'occurred_at'])]
class TicIncident implements Auditable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Who reports it, stamped by the controller. Nullable ONLY so removing a person does not delete the
     * incidents they reported (same idiom as {@see Task::getCreatedBy()}).
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'reported_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $reportedBy = null;

    /**
     * The room it happened in, from the space catalogue. A reference and not free text so the same room
     * is not written five ways and the incidents of one room can actually be counted.
     */
    #[ORM\ManyToOne(targetEntity: Room::class)]
    #[ORM\JoinColumn(name: 'room_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Room $room = null;

    /** What broke: "proyector", "ordenador 12", "carro de portátiles B". */
    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Di qué equipo es.')]
    #[Assert\Length(max: 120)]
    private string $equipment = '';

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Cuenta qué pasa.')]
    #[Assert\Length(max: 2000)]
    private string $description = '';

    /**
     * True when the equipment is for one person, not for a class. Then {@see $groupName} is null and the
     * screens stop asking for it — the centre said it explicitly: "si es un equipo individual, no se
     * incorpora el nombre del grupo y se especifica que es de uso individual no colectivo".
     */
    #[ORM\Column(name: 'individual_use', options: ['default' => false])]
    private bool $individualUse = false;

    /** The group that was in the room, or null for individual-use equipment. */
    #[ORM\Column(name: 'group_name', length: 40, nullable: true)]
    #[Assert\Length(max: 40)]
    private ?string $groupName = null;

    /** When it happened (day and time: "la hora" is part of what the centre asked to record). */
    #[ORM\Column(name: 'occurred_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    /**
     * Who is on it right now, or null while nobody has taken it. Kept apart from {@see $resolvedBy},
     * which is a historical fact: taking an incident on and having fixed it are different claims.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'taken_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $takenBy = null;

    #[ORM\Column(length: 10, enumType: IncidentPriority::class)]
    private IncidentPriority $priority = IncidentPriority::MEDIUM;

    /**
     * The priority's sort weight, materialised. Derived from {@see $priority} and never set from outside:
     * the list is ordered in SQL and "high" sorts AFTER "low" alphabetically, which is backwards.
     */
    #[ORM\Column(name: 'priority_weight', type: Types::SMALLINT, options: ['default' => 1])]
    private int $priorityWeight = 1;

    #[ORM\Column(length: 20, enumType: IncidentStatus::class, options: ['default' => 'open'])]
    private IncidentStatus $status = IncidentStatus::OPEN;

    /** What was done about it, written when closing it. */
    #[ORM\Column(name: 'resolution_note', type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000)]
    private ?string $resolutionNote = null;

    #[ORM\Column(name: 'resolved_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'resolved_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $resolvedBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * A blank incident, as the report form needs one (same idiom as {@see Room}): it fills itself in and
     * the controller stamps who is reporting. "Cuándo" arranca en ahora mismo, que es la respuesta buena
     * el 90 % de las veces — se avisa de lo que se acaba de encontrar.
     */
    public function __construct()
    {
        $this->occurredAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReportedBy(): ?User
    {
        return $this->reportedBy;
    }

    public function setReportedBy(?User $reportedBy): static
    {
        $this->reportedBy = $reportedBy;

        return $this;
    }

    public function getTakenBy(): ?User
    {
        return $this->takenBy;
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

    public function getEquipment(): string
    {
        return $this->equipment;
    }

    public function setEquipment(string $equipment): static
    {
        $this->equipment = trim($equipment);

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = trim($description);

        return $this;
    }

    public function isIndividualUse(): bool
    {
        return $this->individualUse;
    }

    public function getGroupName(): ?string
    {
        return $this->groupName;
    }

    /**
     * Marks the equipment as personal, which by construction clears the group: an individual machine has
     * no class behind it, and leaving a stale group would make the register lie about who was there.
     */
    public function markIndividualUse(): static
    {
        $this->individualUse = true;
        $this->groupName = null;

        return $this;
    }

    /**
     * Marks the equipment as a class's, naming the group that was using it (null when it is not known —
     * an incident found at the end of the day, with nobody around to ask).
     *
     * @param string|null $groupName the group, e.g. "2ºB"
     */
    public function markGroupUse(?string $groupName): static
    {
        $this->individualUse = false;
        $groupName = null !== $groupName ? trim($groupName) : '';
        $this->groupName = '' !== $groupName ? $groupName : null;

        return $this;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function setOccurredAt(\DateTimeImmutable $occurredAt): static
    {
        $this->occurredAt = $occurredAt;

        return $this;
    }

    public function getPriority(): IncidentPriority
    {
        return $this->priority;
    }

    public function setPriority(IncidentPriority $priority): static
    {
        $this->priority = $priority;
        $this->priorityWeight = $priority->weight();

        return $this;
    }

    public function getStatus(): IncidentStatus
    {
        return $this->status;
    }

    /**
     * Moves the incident along. Resolving stamps who and when; reopening (or taking it on) clears that
     * stamp, so "resuelta" always carries its author and never keeps a stale one.
     *
     * @param IncidentStatus $status the new status
     * @param User|null      $actor  who moves it (only recorded when resolving)
     * @param string|null    $note   what was done, when resolving
     */
    public function moveTo(IncidentStatus $status, ?User $actor = null, ?string $note = null): static
    {
        $this->status = $status;

        if (IncidentStatus::IN_PROGRESS === $status) {
            $this->takenBy = $actor;
        }

        if (IncidentStatus::RESOLVED === $status) {
            $this->resolvedAt = new \DateTimeImmutable();
            $this->resolvedBy = $actor;
            $note = null !== $note ? trim($note) : '';
            $this->resolutionNote = '' !== $note ? $note : null;

            return $this;
        }

        $this->resolvedAt = null;
        $this->resolvedBy = null;
        $this->resolutionNote = null;
        if (IncidentStatus::OPEN === $status) {
            $this->takenBy = null;
        }

        return $this;
    }

    public function getResolutionNote(): ?string
    {
        return $this->resolutionNote;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function getResolvedBy(): ?User
    {
        return $this->resolvedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Where it happened, in one line: the room from the catalogue, and the group when there was one.
     *
     * @return string e.g. "Aula 12 · 2ºB" or "Sala de profesores · uso individual"
     */
    public function whereLabel(): string
    {
        $parts = [$this->room?->getName() ?? 'Sin aula'];
        $parts[] = $this->individualUse ? 'uso individual' : ($this->groupName ?? 'sin grupo anotado');

        return implode(' · ', $parts);
    }
}
