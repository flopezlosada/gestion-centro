<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Repository\GuardiaGroupingRepository;
use App\Util\Excerpt;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Several groups of the same period put together in one room, so one guardia teacher can mind them all
 * at once — what the centre does when there are more absences than people to cover them.
 *
 * Additive on purpose: it does NOT touch the {@see GuardiaCover} lines it gathers. Each keeps its absent
 * teacher, its group, its task document and its assigned substitute, so the parte still reads per class,
 * the equitable counter keeps counting the same way and nothing is lost if the grouping is undone.
 * All this row says is "these classes are together in room X".
 *
 * {@see $displacedToRoom} is where the class that WAS in that room is sent, when the room chosen is not
 * free — freeing up the library or the assembly hall is exactly the centre's case. Who was displaced is
 * deliberately not stored: it is always re-derivable from the effective timetable
 * ({@see \App\Space\RoomOccupancy::at()}), and a copy would be one more thing to keep in step with a
 * re-import. What cannot be re-derived, and is therefore stored, is where they were sent.
 *
 * There is no edit path: a grouping is undone and made again. That keeps the notices honest — every
 * change of room is one cancellation and one new arrangement, never a silent overwrite.
 *
 * Auditable: it moves people around the building, so who arranged it and when belongs in the trail.
 */
#[ORM\Entity(repositoryClass: GuardiaGroupingRepository::class)]
#[ORM\Table(name: 'guardia_grouping')]
#[ORM\Index(name: 'IDX_grouping_date_slot', columns: ['grouping_date', 'slot_index'])]
#[ORM\UniqueConstraint(name: 'UNIQ_guardia_grouping', columns: ['grouping_date', 'slot_index', 'room_name'])]
class GuardiaGrouping implements Auditable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The day the groups are put together. */
    #[ORM\Column(name: 'grouping_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    /** The period within the day (0-based Peñalara {@code indice}), matching {@see ScheduleEntry::$slotIndex}. */
    #[ORM\Column(name: 'slot_index', type: Types::SMALLINT)]
    private int $slotIndex;

    /** The room everybody is sent to (short name, as the timetable spells it: "S ACTOS", "BIBL"). */
    #[ORM\Column(name: 'room_name', length: 64)]
    private string $roomName;

    /**
     * Where the class that was already in that room is sent, when it had to make way. Null when the room
     * was free — the ordinary case — and the difference matters: null means nobody was moved.
     */
    #[ORM\Column(name: 'displaced_to_room', length: 64, nullable: true)]
    private ?string $displacedToRoom = null;

    /** Anything the coordinator wants the people involved to read ("os espera Conchi en la puerta"). */
    #[ORM\Column(name: 'note', length: 255, nullable: true)]
    private ?string $note = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getRoomName(): string
    {
        return $this->roomName;
    }

    public function setRoomName(string $roomName): static
    {
        $this->roomName = $roomName;

        return $this;
    }

    public function getDisplacedToRoom(): ?string
    {
        return $this->displacedToRoom;
    }

    public function setDisplacedToRoom(?string $displacedToRoom): static
    {
        $this->displacedToRoom = null !== $displacedToRoom && '' !== trim($displacedToRoom) ? trim($displacedToRoom) : null;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    /**
     * Sets the note, normalising blank to null and clamping it to what the column holds. Clamped here
     * rather than trusted from the form: a value over 255 characters would otherwise surface as a 500
     * instead of as a saved note.
     *
     * @param string|null $note the note for the people involved, or null/blank for none
     */
    public function setNote(?string $note): static
    {
        $clamped = Excerpt::of($note, 255);
        $this->note = '' !== $clamped ? $clamped : null;

        return $this;
    }
}
