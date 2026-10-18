<?php

declare(strict_types=1);

namespace App\Entity;

use App\Family\FamilySession;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An activity that happens once, on a day and time of its own: a workshop, a talk, a trip — what the
 * timetable does not hold. Its one session is itself ({@see session()}).
 */
#[ORM\Entity]
class OneOffFamilyActivity extends FamilyActivity
{
    #[ORM\Column(name: 'start_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startAt;

    /** End instant on the same day, or null when only the start is known. */
    #[ORM\Column(name: 'end_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endAt = null;

    /**
     * @param User               $organizer who publishes it
     * @param string             $title     what it is
     * @param \DateTimeImmutable $startAt   when it starts
     */
    public function __construct(User $organizer, string $title, \DateTimeImmutable $startAt)
    {
        parent::__construct($organizer, $title);
        $this->startAt = $startAt;
    }

    /**
     * Not weekly: it happens once.
     */
    public function isWeekly(): bool
    {
        return false;
    }

    /**
     * @return \DateTimeImmutable when it starts
     */
    public function getStartAt(): \DateTimeImmutable
    {
        return $this->startAt;
    }

    /**
     * @return \DateTimeImmutable|null when it ends, or null if only the start is known
     */
    public function getEndAt(): ?\DateTimeImmutable
    {
        return $this->endAt;
    }

    /**
     * Sets both ends at once, so an end before the start can never be stored. Its one session moves with
     * it, and so do the families coming: they were coming to the activity, not to the old date.
     *
     * @param \DateTimeImmutable      $startAt when it starts
     * @param \DateTimeImmutable|null $endAt   when it ends, or null
     *
     * @throws \InvalidArgumentException if the end is not after the start
     */
    public function reschedule(\DateTimeImmutable $startAt, ?\DateTimeImmutable $endAt): static
    {
        if (null !== $endAt && $endAt <= $startAt) {
            throw new \InvalidArgumentException('An activity must end after it starts.');
        }
        $this->startAt = $startAt;
        $this->endAt = $endAt;
        $this->moveSignupsTo($startAt);

        return $this;
    }

    /**
     * @return FamilySession its one session, on the day it was set for
     */
    public function session(): FamilySession
    {
        return new FamilySession($this, $this->startAt, $this->endAt, $this->getPlace());
    }
}
