<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Enum\RoomKind;
use App\Repository\RoomRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A physical space of the centre: a classroom, a lab, the assembly hall, a court. The catalogue the
 * space-management module reasons over — "which rooms are free at 3rd period on Tuesday", "where can
 * this group go while the hall is taken".
 *
 * Rooms are DISCOVERED, not typed in from scratch: the Peñalara timetable already names every space in
 * use, so {@see \App\Space\RoomSynchroniser} creates a stub card for each one it finds and a person
 * only completes what the export cannot know. Verified against the centre's real planificador: its
 * {@code <aula>} elements carry a name and an export code and nothing else — no capacity, no type. So
 * {@see $capacity} and {@see $kind} are centre-supplied and {@see $capacity} stays null until somebody
 * fills it, never guessed.
 *
 * A room is never deleted once the timetable references it (that would silently drop the cells that
 * point at it from every occupancy calculation): it is deactivated instead, see {@see $active}.
 *
 * {@see Auditable} like the other admin catalogues: capacity and assignability decide where groups end
 * up, so a change has to leave a trail.
 */
#[ORM\Entity(repositoryClass: RoomRepository::class)]
#[ORM\Table(name: 'room')]
#[ORM\UniqueConstraint(name: 'uniq_room_code', columns: ['code'])]
#[UniqueEntity(fields: ['code'], message: 'Ya existe un espacio con ese código.')]
class Room implements Auditable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * The short code the timetable uses for this space ("2IN5", "S ACTOS", "LABQ") — the Peñalara
     * {@code abreviatura}. Kept normalised ({@see normaliseCode()}) so a stray space or a lower-case
     * letter can never create a second card for the same room, whatever the database collation does.
     */
    #[ORM\Column(length: 64)]
    #[Assert\NotBlank(message: 'Indica el código del espacio (el que usa el horario, p. ej. "2IN5").')]
    #[Assert\Length(max: 64)]
    private string $code;

    /** Human-facing name ("Aula de Inglés 5"). Defaults to the code when the card is auto-created. */
    #[ORM\Column(length: 128)]
    #[Assert\NotBlank(message: 'Ponle un nombre al espacio.')]
    #[Assert\Length(max: 128)]
    private string $name;

    /** What kind of space it is; unknown ({@see RoomKind::OTHER}) until someone classifies it. */
    #[ORM\Column(length: 24, enumType: RoomKind::class, options: ['default' => 'other'])]
    private RoomKind $kind = RoomKind::OTHER;

    /**
     * How many people fit. Null means UNKNOWN, not zero: the export does not carry it, so until a
     * person fills it in the module can only order rooms by size, never rule one out for not fitting.
     */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    #[Assert\Positive(message: 'La capacidad debe ser un número mayor que cero.')]
    #[Assert\LessThanOrEqual(value: 500, message: 'Esa capacidad no parece realista.')]
    private ?int $capacity = null;

    /** Building or wing, when the centre has more than one. Feeds the "do not send them across the centre" criterion. */
    #[ORM\Column(length: 32, nullable: true)]
    #[Assert\Length(max: 32)]
    private ?string $building = null;

    /** Floor number (0 = ground). Same purpose as {@see $building}. Column renamed: FLOOR() is a SQL function. */
    #[ORM\Column(name: 'floor_level', type: Types::SMALLINT, nullable: true)]
    #[Assert\Range(min: -2, max: 10, notInRangeMessage: 'Indica una planta entre {{ min }} y {{ max }}.')]
    private ?int $floor = null;

    /**
     * Whether a displaced group may be sent here. True for ordinary classrooms and the library; false
     * for spaces that cannot host a lesson (the courts) or that the centre keeps off-limits. A room can
     * be occupied by the timetable and still not be assignable — the two are different questions.
     */
    #[ORM\Column(options: ['default' => true])]
    private bool $assignable = true;

    /** Whether the space is in use. Retiring a room deactivates it; the historical cells keep pointing here. */
    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    /** Anything a person needs to know before sending a group here ("sin proyector", "solo con llave de conserjería"). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    /**
     * Normalises a room code for comparison and storage: trimmed, inner whitespace collapsed to a
     * single space, upper-cased. The timetable and the people who type cards in must land on the same
     * string or the catalogue silently grows a duplicate for "bibl" next to "BIBL".
     *
     * @param string $code the raw code, as typed or as exported
     *
     * @return string the normalised code
     */
    public static function normaliseCode(string $code): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $code)));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * Sets the code, normalised. Normalising in the setter (rather than at each call site) is what
     * makes a duplicate card impossible to create by accident.
     *
     * @param string $code the raw code
     */
    public function setCode(string $code): static
    {
        $this->code = self::normaliseCode($code);

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getKind(): RoomKind
    {
        return $this->kind;
    }

    public function setKind(RoomKind $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function getCapacity(): ?int
    {
        return $this->capacity;
    }

    public function setCapacity(?int $capacity): static
    {
        $this->capacity = $capacity;

        return $this;
    }

    public function getBuilding(): ?string
    {
        return $this->building;
    }

    public function setBuilding(?string $building): static
    {
        $this->building = $building;

        return $this;
    }

    public function getFloor(): ?int
    {
        return $this->floor;
    }

    public function setFloor(?int $floor): static
    {
        $this->floor = $floor;

        return $this;
    }

    public function isAssignable(): bool
    {
        return $this->assignable;
    }

    public function setAssignable(bool $assignable): static
    {
        $this->assignable = $assignable;

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

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    /**
     * Whether the card still lacks the data a person has to supply — today, its capacity and a type.
     * Drives the "sin completar" marker in the catalogue: an auto-created stub is useful (it makes the
     * room exist) but it cannot inform a decision until somebody finishes it.
     *
     * @return bool true when capacity or kind are still unknown
     */
    public function needsReview(): bool
    {
        return null === $this->capacity || RoomKind::OTHER === $this->kind;
    }

    /**
     * The name to show, falling back to the code for auto-created cards nobody has named yet.
     *
     * @return string the display label
     */
    public function getLabel(): string
    {
        return $this->name !== $this->code ? sprintf('%s (%s)', $this->name, $this->code) : $this->code;
    }
}
