<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Weekday;
use App\Repository\FamilyAvailabilityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * When a family can usually come to the centre: one span of hours on one day of the school week ("los
 * martes de 9:00 a 12:00"). What the staff look at to choose the day of an activity, before any family
 * signs up for it.
 *
 * One per family and day: a family that can come twice on the same day gives the span that covers both,
 * which is all a teacher choosing a day needs. It goes with the family's account, like its signups.
 *
 * Deliberately NOT {@see \App\Contract\Auditable}, for the reason given in {@see FamilySignup}: the
 * activity trail outlives the account.
 */
#[ORM\Entity(repositoryClass: FamilyAvailabilityRepository::class)]
#[ORM\Table(name: 'family_availability')]
#[ORM\UniqueConstraint(name: 'uniq_family_availability_day', columns: ['family_id', 'weekday'])]
class FamilyAvailability
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: FamilyVolunteer::class)]
    #[ORM\JoinColumn(name: 'family_id', nullable: false, onDelete: 'CASCADE')]
    private FamilyVolunteer $family;

    /** The day of the week, ISO-8601 (Monday = 1). */
    #[ORM\Column(name: 'weekday', type: Types::SMALLINT, enumType: Weekday::class)]
    private Weekday $weekday;

    /** From when they can be there; only the time of day carries meaning. */
    #[ORM\Column(name: 'available_from', type: Types::TIME_IMMUTABLE)]
    private \DateTimeImmutable $from;

    /** Until when they can be there; always after {@see $from}. */
    #[ORM\Column(name: 'available_until', type: Types::TIME_IMMUTABLE)]
    private \DateTimeImmutable $until;

    /**
     * @param FamilyVolunteer    $family  who can come
     * @param Weekday            $weekday on which day of the week
     * @param \DateTimeImmutable $from    from when (time of day)
     * @param \DateTimeImmutable $until   until when (time of day), after $from
     *
     * @throws \InvalidArgumentException if the span does not end after it starts
     */
    public function __construct(FamilyVolunteer $family, Weekday $weekday, \DateTimeImmutable $from, \DateTimeImmutable $until)
    {
        $this->family = $family;
        $this->weekday = $weekday;
        $this->changeHours($from, $until);
    }

    /**
     * @return int|null the id, null until persisted
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return FamilyVolunteer who can come
     */
    public function getFamily(): FamilyVolunteer
    {
        return $this->family;
    }

    /**
     * @return Weekday on which day of the week
     */
    public function getWeekday(): Weekday
    {
        return $this->weekday;
    }

    /**
     * @return \DateTimeImmutable from when (time of day)
     */
    public function getFrom(): \DateTimeImmutable
    {
        return $this->from;
    }

    /**
     * @return \DateTimeImmutable until when (time of day)
     */
    public function getUntil(): \DateTimeImmutable
    {
        return $this->until;
    }

    /**
     * Moves the span. Compares the "HH:MM" of each end, not the instants: the time fields of a form carry
     * a date of their own that means nothing here.
     *
     * @param \DateTimeImmutable $from  from when (time of day)
     * @param \DateTimeImmutable $until until when (time of day), after $from
     *
     * @throws \InvalidArgumentException if the span does not end after it starts
     */
    public function changeHours(\DateTimeImmutable $from, \DateTimeImmutable $until): void
    {
        if ($until->format('H:i') <= $from->format('H:i')) {
            throw new \InvalidArgumentException('A family\'s availability must end after it starts.');
        }
        $this->from = $from;
        $this->until = $until;
    }
}
