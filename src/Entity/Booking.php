<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Repository\BookingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A booking of one thing for one period of one day: an aula específica, the radio, a laptop trolley.
 *
 * WHEN is a day plus a period of the timetable ({@see $slotIndex}), and not a pair of clock times, because
 * that is how a centre speaks: "el martes a tercera", not "el martes de 11:15 a 12:10". It also makes the
 * clash check exact — two people asking for the same period either collide or they do not, with no
 * arithmetic on overlapping ranges.
 *
 * WHAT is a {@see Room} or a {@see Material}, exactly one of the two. They are two nullable columns and
 * not two tables because everything else about a booking — who, when, what for, the clash — is identical,
 * and duplicating it would mean fixing every bug twice.
 *
 * {@see $resourceKey} is what makes the clash IMPOSSIBLE rather than merely unlikely: a string derived
 * from whichever of the two is set ("room:12", "material:3"), carrying a UNIQUE index together with the
 * day and the period. A check-then-insert in the service would leave a window in which two people
 * booking the same trolley at the same second both succeed; here the second one bounces off the
 * database. It is derived, never set from outside.
 *
 * There is NO approval step. The centre called it "solicitud de reserva", but what they do today in the
 * Aula Virtual is book, and adding somebody who has to say yes to each request would only add a queue:
 * whoever asks first has it, and it is visible to everybody.
 */
#[ORM\Entity(repositoryClass: BookingRepository::class)]
#[ORM\Table(name: 'booking')]
#[ORM\UniqueConstraint(name: 'uniq_booking_slot', columns: ['resource_key', 'booked_on', 'slot_index'])]
// Serves the two listings: the day's bookings and a person's own.
#[ORM\Index(name: 'idx_booking_day', columns: ['booked_on', 'slot_index'])]
#[ORM\Index(name: 'idx_booking_person', columns: ['booked_by_id', 'booked_on'])]
class Booking implements Auditable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Who booked it. Nullable ONLY so removing a person does not delete the bookings (same idiom as the
     * rest); the constructors always demand one.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'booked_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $bookedBy;

    #[ORM\ManyToOne(targetEntity: Room::class)]
    #[ORM\JoinColumn(name: 'room_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Room $room = null;

    #[ORM\ManyToOne(targetEntity: Material::class)]
    #[ORM\JoinColumn(name: 'material_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Material $material = null;

    /** Derived from whichever of room/material is set; the UNIQUE index rides on it. See the class doc. */
    #[ORM\Column(name: 'resource_key', length: 32)]
    private string $resourceKey;

    #[ORM\Column(name: 'booked_on', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    /** The period of the timetable, 0-based, same numbering the guardias use. */
    #[ORM\Column(name: 'slot_index', type: Types::SMALLINT)]
    private int $slotIndex;

    /** What it is for: "examen de recuperación", "grabación del podcast", "salida a la granja". */
    #[ORM\Column(length: 200)]
    #[Assert\NotBlank(message: 'Di para qué lo necesitas.')]
    #[Assert\Length(max: 200)]
    private string $purpose;

    /** The group it is for, when there is one. */
    #[ORM\Column(name: 'group_name', length: 40, nullable: true)]
    #[Assert\Length(max: 40)]
    private ?string $groupName = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    private function __construct(User $bookedBy, string $resourceKey, \DateTimeImmutable $date, int $slotIndex, string $purpose)
    {
        $this->bookedBy = $bookedBy;
        $this->resourceKey = $resourceKey;
        // Anclada a medianoche: una reserva es de un DÍA, y guardar la hora a la que se pidió haría que
        // dos reservas del mismo día no coincidieran en la comparación.
        $this->date = $date->setTime(0, 0);
        $this->slotIndex = $slotIndex;
        $this->purpose = trim($purpose);
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * Books a room. The only way to make one: the resource key cannot then disagree with the room.
     */
    public static function forRoom(User $bookedBy, Room $room, \DateTimeImmutable $date, int $slotIndex, string $purpose): self
    {
        $booking = new self($bookedBy, 'room:'.$room->getId(), $date, $slotIndex, $purpose);
        $booking->room = $room;

        return $booking;
    }

    /**
     * Books a piece of material. The counterpart of {@see forRoom()}.
     */
    public static function forMaterial(User $bookedBy, Material $material, \DateTimeImmutable $date, int $slotIndex, string $purpose): self
    {
        $booking = new self($bookedBy, 'material:'.$material->getId(), $date, $slotIndex, $purpose);
        $booking->material = $material;

        return $booking;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBookedBy(): ?User
    {
        return $this->bookedBy;
    }

    public function getRoom(): ?Room
    {
        return $this->room;
    }

    public function getMaterial(): ?Material
    {
        return $this->material;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getSlotIndex(): int
    {
        return $this->slotIndex;
    }

    public function getPurpose(): string
    {
        return $this->purpose;
    }

    public function setPurpose(string $purpose): static
    {
        $this->purpose = trim($purpose);

        return $this;
    }

    public function getGroupName(): ?string
    {
        return $this->groupName;
    }

    public function setGroupName(?string $groupName): static
    {
        $groupName = null !== $groupName ? trim($groupName) : '';
        $this->groupName = '' !== $groupName ? $groupName : null;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * What is booked, by name — the room's or the material's.
     *
     * @return string the name of the booked thing
     */
    public function resourceName(): string
    {
        return $this->room?->getName() ?? $this->material?->getName() ?? 'Recurso retirado';
    }

    /**
     * Whether the given person may undo this booking: the one who made it, or an admin (checked by the
     * caller). Cancelling somebody else's booking is how a centre ends up with two people convinced they
     * have the radio.
     *
     * @param User $user the person asking
     *
     * @return bool true when it is theirs
     */
    public function isOwnedBy(User $user): bool
    {
        return $this->bookedBy === $user;
    }
}
