<?php

declare(strict_types=1);

namespace App\Family;

use App\Entity\FamilyActivity;
use App\Entity\FamilySignup;
use App\Entity\FamilyVolunteer;

/**
 * One occasion families can come to: an activity on one day, at its times. A one-off activity has one;
 * a weekly one has one each week its class is given. Never stored — worked out from the activity and,
 * for a weekly one, from the organiser's timetable ({@see FamilySessions}) — so a holiday or a change of
 * timetable needs nothing cancelled by hand.
 */
final readonly class FamilySession
{
    /** The day, at midnight: what a {@see FamilySignup} is for. */
    public \DateTimeImmutable $day;

    /**
     * @internal built by {@see FamilySessions} or {@see \App\Entity\OneOffFamilyActivity::session()}, which
     *           know the activity really takes place then
     *
     * @param FamilyActivity          $activity the activity
     * @param \DateTimeImmutable      $startsAt when this session starts
     * @param \DateTimeImmutable|null $endsAt   when it ends, or null if only the start is known
     * @param string|null             $place    where it is held, or null
     */
    public function __construct(
        public FamilyActivity $activity,
        public \DateTimeImmutable $startsAt,
        public ?\DateTimeImmutable $endsAt,
        public ?string $place,
    ) {
        $this->day = $startsAt->setTime(0, 0);
    }

    /**
     * @return list<FamilySignup> the families coming to this session, in the order they signed up
     */
    public function signups(): array
    {
        return $this->activity->signupsOn($this->day);
    }

    /**
     * @param FamilyVolunteer $family the family
     *
     * @return bool whether it is coming to this session
     */
    public function isSignedUpBy(FamilyVolunteer $family): bool
    {
        return $this->activity->isSignedUpBy($family, $this->day);
    }

    /**
     * @return int|null the places left in this session, or null when any number helps
     */
    public function placesLeft(): ?int
    {
        return $this->activity->placesLeftOn($this->day);
    }

    /**
     * Whether signing up or withdrawing is still possible: only before it starts, so what happened stays
     * as it was.
     *
     * @param \DateTimeImmutable $now the current instant
     */
    public function isOpenAt(\DateTimeImmutable $now): bool
    {
        return $now < $this->startsAt;
    }

    /**
     * Whether a family not yet coming could sign up now: not started and not full.
     *
     * @param \DateTimeImmutable $now the current instant
     */
    public function acceptsSignupsAt(\DateTimeImmutable $now): bool
    {
        return $this->isOpenAt($now) && 0 !== $this->placesLeft();
    }
}
