<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Enum\Weekday;
use App\Repository\RecurringBookingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A fixed weekly booking: a laptop trolley or a room held at the same periods every week, for the whole
 * course or a stretch of it — "el carro Dora Maar, los lunes a 2ª y 3ª, para Mar Medina, hasta junio".
 *
 * It does NOT replace the {@see Booking} rows, it produces them: one per teaching day and period, made all
 * at once when the series is created. That is what keeps every other screen honest without touching them —
 * the day, the week, the tracking sheet and "what is free" all read bookings, and the UNIQUE index that
 * makes a clash impossible for a one-off booking makes it impossible here too. A series computed on the fly
 * instead would have to be taught to each of those screens, and to the clash check, one by one.
 *
 * The cost of producing the rows up front is that the series is fixed when it is made: a day the centre
 * declares non-teaching AFTERWARDS keeps its rows. That is harmless (nobody is there to use the trolley)
 * and the holder can free the day by hand, which is the same gesture as freeing any other.
 *
 * WHO HOLDS IT is a person OR a department, exactly one of the two: Ada Lovelace belongs to Tecnología, not
 * to whoever happens to teach the class. It is set only through the constructor, from a `User|Department`,
 * so a series with both or neither cannot be built. Both columns are nullable only so removing a person or a
 * department does not delete the bookings history (same idiom as {@see Booking::$bookedBy}).
 *
 * There is no "edit": changing the pattern is ending the series and making another. A series is ended, not
 * deleted, once any of its days has passed — the past rows are the record of who had the trolley, and the
 * tracking sheet reads them.
 */
#[ORM\Entity(repositoryClass: RecurringBookingRepository::class)]
#[ORM\Table(name: 'recurring_booking')]
class RecurringBooking implements Auditable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Room::class)]
    #[ORM\JoinColumn(name: 'room_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Room $room = null;

    #[ORM\ManyToOne(targetEntity: Material::class)]
    #[ORM\JoinColumn(name: 'material_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Material $material = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'holder_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $holderUser = null;

    #[ORM\ManyToOne(targetEntity: Department::class)]
    #[ORM\JoinColumn(name: 'holder_department_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Department $holderDepartment = null;

    /** Who made it: Dirección or whoever the centre gave the permission to. Not the holder. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    #[ORM\Column(name: 'starts_on', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startsOn;

    #[ORM\Column(name: 'ends_on', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $endsOn;

    /**
     * The weekly periods it takes, as ISO weekday → period indexes ({"1": [1, 2], "5": [2, 3, 4]}). Always
     * written through the constructor, which sorts it and drops empty days, so two equal patterns are equal
     * here too.
     *
     * @var array<int, list<int>>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $pattern;

    #[ORM\Column(length: 200)]
    private string $purpose;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<int, list<int>> $pattern ISO weekday → period indexes; must hold at least one period
     */
    public function __construct(
        User $createdBy,
        Room|Material $resource,
        User|Department $holder,
        \DateTimeImmutable $startsOn,
        \DateTimeImmutable $endsOn,
        array $pattern,
        string $purpose,
    ) {
        $pattern = self::normalise($pattern);
        if ([] === $pattern) {
            throw new \InvalidArgumentException('A recurring booking needs at least one period.');
        }
        if ($endsOn < $startsOn) {
            throw new \InvalidArgumentException('A recurring booking cannot end before it starts.');
        }

        $this->createdBy = $createdBy;
        if ($resource instanceof Room) {
            $this->room = $resource;
        } else {
            $this->material = $resource;
        }
        if ($holder instanceof User) {
            $this->holderUser = $holder;
        } else {
            $this->holderDepartment = $holder;
        }
        $this->startsOn = $startsOn->setTime(0, 0);
        $this->endsOn = $endsOn->setTime(0, 0);
        $this->pattern = $pattern;
        $this->purpose = trim($purpose);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRoom(): ?Room
    {
        return $this->room;
    }

    public function getMaterial(): ?Material
    {
        return $this->material;
    }

    public function getHolderUser(): ?User
    {
        return $this->holderUser;
    }

    public function getHolderDepartment(): ?Department
    {
        return $this->holderDepartment;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getStartsOn(): \DateTimeImmutable
    {
        return $this->startsOn;
    }

    public function getEndsOn(): \DateTimeImmutable
    {
        return $this->endsOn;
    }

    public function getPurpose(): string
    {
        return $this->purpose;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * The weekly pattern, ISO weekday → period indexes, both ascending.
     *
     * @return array<int, list<int>> the pattern
     */
    public function getPattern(): array
    {
        return $this->pattern;
    }

    /**
     * The periods it takes on a weekday.
     *
     * @param Weekday $weekday the weekday
     *
     * @return list<int> the period indexes, ascending; empty when it takes none that day
     */
    public function slotsOn(Weekday $weekday): array
    {
        return $this->pattern[$weekday->value] ?? [];
    }

    /**
     * Ends the series on the given day: from then on it holds nothing. Only ever shortens it, and never to
     * before it started — a series whose days have not begun is deleted, not ended.
     *
     * @param \DateTimeImmutable $lastDay the last day it still holds
     */
    public function endOn(\DateTimeImmutable $lastDay): void
    {
        $lastDay = $lastDay->setTime(0, 0);
        if ($lastDay < $this->startsOn || $lastDay > $this->endsOn) {
            throw new \InvalidArgumentException('A recurring booking can only be shortened within its own dates.');
        }
        $this->endsOn = $lastDay;
    }

    /**
     * What is held, by name.
     *
     * @return string the name of the room or material
     */
    public function resourceName(): string
    {
        return $this->room?->getName() ?? $this->material?->getName() ?? 'Recurso retirado';
    }

    /**
     * Who holds it, by name: the person or the department.
     *
     * @return string the holder's name
     */
    public function holderName(): string
    {
        return $this->holderUser?->getFullName() ?? $this->holderDepartment?->getName() ?? 'Titular dado de baja';
    }

    /**
     * Whether the given person may free one of its days: the person who holds it, the head of the
     * department that holds it, or whoever made it. Admins are checked by the caller, as with one-off
     * bookings.
     *
     * The head and not every member of the department: a department's trolley freed by any of its teachers
     * is a trolley nobody in the department can count on.
     *
     * @param User $user the person asking
     *
     * @return bool true when they may
     */
    public function mayBeReleasedBy(User $user): bool
    {
        return $this->holderUser === $user
            || $this->createdBy === $user
            || (null !== $this->holderDepartment && $user->getUnit() === $this->holderDepartment && $user->holdsRoleCode('head_dept'));
    }

    /**
     * Sorts the pattern and drops weekdays without periods, so it is stored in one shape only.
     *
     * @param array<int, list<int>> $pattern ISO weekday → period indexes
     *
     * @return array<int, list<int>> the same pattern, normalised
     */
    private static function normalise(array $pattern): array
    {
        $clean = [];
        foreach ($pattern as $weekday => $slots) {
            $slots = array_values(array_unique(array_map('intval', $slots)));
            sort($slots);
            if ([] !== $slots) {
                $clean[(int) $weekday] = $slots;
            }
        }
        ksort($clean);

        return $clean;
    }
}
