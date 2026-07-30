<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RoomKind;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Something the event brings into the centre: the external exam that takes the assembly hall on Tuesday
 * from 9 to 11, a workshop that needs three sessions somewhere with room for thirty.
 *
 * This is the ENUNCIADO, not the outcome: it does not depend on which alternative is chosen, which is
 * why it hangs off the {@see SpacePlan} and not off an option. What varies between alternatives is
 * where the displaced classes end up — and, for an activity with no room of its own, where it lands.
 *
 * One entity covers what looked like two things: an external occupation is an activity with its room
 * and times FIXED ({@see $room} and {@see $fixedSlots} set), and a workshop to be placed is one with
 * both left open. The proposer only asks whether they are filled in.
 */
#[ORM\Entity]
#[ORM\Table(name: 'space_plan_activity')]
class SpacePlanActivity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SpacePlan::class, inversedBy: 'activities')]
    #[ORM\JoinColumn(name: 'plan_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SpacePlan $plan;

    /** What it is: "Prueba EOI", "Examen de Matemáticas II", "Taller de primeros auxilios". */
    #[ORM\Column(length: 160)]
    #[Assert\NotBlank(message: 'Di qué es (por ejemplo, "Prueba de la EOI").')]
    #[Assert\Length(max: 160)]
    private string $title;

    /**
     * The space it takes, when the event imposes one. NULL means "the engine picks": the difference
     * between "the exam is in the assembly hall" and "this workshop needs a room, find one".
     */
    #[ORM\ManyToOne(targetEntity: Room::class)]
    #[ORM\JoinColumn(name: 'room_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Room $room = null;

    /** The day it happens on, when fixed; null when the engine places it within the plan's dates. */
    #[ORM\Column(name: 'fixed_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $fixedDate = null;

    /**
     * The periods it takes on {@see $fixedDate}, when fixed. Empty when the engine places it.
     *
     * @var list<int>
     */
    #[ORM\Column(name: 'fixed_slots', type: Types::JSON)]
    private array $fixedSlots = [];

    /** How many sessions to place, when they are not fixed (a workshop repeated through the day). */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    #[Assert\Positive(message: 'El número de sesiones debe ser mayor que cero.')]
    private ?int $sessions = null;

    /** How many people must fit, when known. Null = unknown, and then capacity cannot rule a room out. */
    #[ORM\Column(name: 'required_capacity', type: Types::SMALLINT, nullable: true)]
    #[Assert\Positive(message: 'El aforo debe ser mayor que cero.')]
    private ?int $requiredCapacity = null;

    /** A kind of space it cannot do without ("esto necesita informática"), or null for any. */
    #[ORM\Column(name: 'required_kind', length: 24, enumType: RoomKind::class, nullable: true)]
    private ?RoomKind $requiredKind = null;

    /**
     * The groups it is aimed at, as the timetable spells them.
     *
     * @var list<string>
     */
    #[ORM\Column(name: 'target_group_names', type: Types::JSON)]
    private array $targetGroupNames = [];

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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

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

    public function getFixedDate(): ?\DateTimeImmutable
    {
        return $this->fixedDate;
    }

    public function setFixedDate(?\DateTimeImmutable $fixedDate): static
    {
        $this->fixedDate = $fixedDate;

        return $this;
    }

    /**
     * @return list<int> the fixed periods
     */
    public function getFixedSlots(): array
    {
        return $this->fixedSlots;
    }

    /**
     * @param list<int> $fixedSlots the periods it takes
     */
    public function setFixedSlots(array $fixedSlots): static
    {
        $slots = array_values(array_unique(array_map(static fn (int $s): int => $s, $fixedSlots)));
        sort($slots);
        $this->fixedSlots = $slots;

        return $this;
    }

    public function getSessions(): ?int
    {
        return $this->sessions;
    }

    public function setSessions(?int $sessions): static
    {
        $this->sessions = $sessions;

        return $this;
    }

    public function getRequiredCapacity(): ?int
    {
        return $this->requiredCapacity;
    }

    public function setRequiredCapacity(?int $requiredCapacity): static
    {
        $this->requiredCapacity = $requiredCapacity;

        return $this;
    }

    public function getRequiredKind(): ?RoomKind
    {
        return $this->requiredKind;
    }

    public function setRequiredKind(?RoomKind $requiredKind): static
    {
        $this->requiredKind = $requiredKind;

        return $this;
    }

    /**
     * @return list<string> the groups it is aimed at
     */
    public function getTargetGroupNames(): array
    {
        return $this->targetGroupNames;
    }

    /**
     * @param list<string> $targetGroupNames the group names
     */
    public function setTargetGroupNames(array $targetGroupNames): static
    {
        $this->targetGroupNames = array_values(array_unique(array_filter(
            array_map(static fn (string $g): string => trim($g), $targetGroupNames),
            static fn (string $g): bool => '' !== $g,
        )));

        return $this;
    }

    /**
     * Whether the activity states where and when it happens. A fixed activity BLOCKS its room for those
     * periods (and displaces whoever the timetable put there); an unfixed one is something the engine
     * still has to place.
     *
     * @return bool true when both a room and a date with periods are set
     */
    public function isFixed(): bool
    {
        return null !== $this->room && null !== $this->fixedDate && [] !== $this->fixedSlots;
    }

    /**
     * Whether the activity occupies the given room at the given moment.
     *
     * @param Room               $room      the space
     * @param \DateTimeImmutable $date      the day
     * @param int                $slotIndex the period index
     *
     * @return bool true when this activity is sitting there
     */
    public function occupies(Room $room, \DateTimeImmutable $date, int $slotIndex): bool
    {
        return $this->isFixed()
            && $this->room?->getId() === $room->getId()
            && $this->fixedDate?->format('Y-m-d') === $date->format('Y-m-d')
            && \in_array($slotIndex, $this->fixedSlots, true);
    }
}
