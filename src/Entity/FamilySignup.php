<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A family that has said it will come to one session of an activity ({@see \App\Family\FamilySession}):
 * the activity and the day.
 *
 * Only {@see FamilyActivity::signUp()} creates one, so the rules (not started, not full, once per family
 * and session) hold for every signup stored. It goes with either end: removing the activity or the family's account
 * removes it too, so a removed account leaves no trace of where that person went.
 *
 * Deliberately NOT {@see \App\Contract\Auditable}: the activity trail outlives the account, and a row
 * per signup ("familia #12 came to this activity") would keep exactly that trace after it is removed.
 */
#[ORM\Entity]
#[ORM\Table(name: 'family_signup')]
#[ORM\UniqueConstraint(name: 'uniq_family_signup_session', columns: ['activity_id', 'family_id', 'session_date'])]
class FamilySignup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: FamilyActivity::class, inversedBy: 'signups')]
    #[ORM\JoinColumn(name: 'activity_id', nullable: false, onDelete: 'CASCADE')]
    private FamilyActivity $activity;

    #[ORM\ManyToOne(targetEntity: FamilyVolunteer::class)]
    #[ORM\JoinColumn(name: 'family_id', nullable: false, onDelete: 'CASCADE')]
    private FamilyVolunteer $family;

    /** The day of the session the family comes to. */
    #[ORM\Column(name: 'session_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $sessionDate;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @internal use {@see FamilyActivity::signUp()}, which checks the rules first
     *
     * @param FamilyActivity     $activity the activity
     * @param FamilyVolunteer    $family   who comes
     * @param \DateTimeImmutable $day      the day of the session
     * @param \DateTimeImmutable $now      when they signed up
     */
    public function __construct(FamilyActivity $activity, FamilyVolunteer $family, \DateTimeImmutable $day, \DateTimeImmutable $now)
    {
        $this->activity = $activity;
        $this->family = $family;
        $this->sessionDate = $day->setTime(0, 0);
        $this->createdAt = $now;
    }

    /**
     * @return int|null the id, null until persisted
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return FamilyActivity the activity
     */
    public function getActivity(): FamilyActivity
    {
        return $this->activity;
    }

    /**
     * @return FamilyVolunteer who comes
     */
    public function getFamily(): FamilyVolunteer
    {
        return $this->family;
    }

    /**
     * @return \DateTimeImmutable the day of the session the family comes to
     */
    public function getSessionDate(): \DateTimeImmutable
    {
        return $this->sessionDate;
    }

    /**
     * @internal only {@see FamilyActivity}, when its one session moves to another day
     *
     * @param \DateTimeImmutable $day the day of the session now
     */
    public function moveTo(\DateTimeImmutable $day): void
    {
        $this->sessionDate = $day->setTime(0, 0);
    }

    /**
     * Whether this signup is for the session on that day. Compares the calendar day, not the instant: the
     * day read back from the database and the one worked out from the timetable need not share a time.
     *
     * @param \DateTimeImmutable $day a day
     */
    public function isFor(\DateTimeImmutable $day): bool
    {
        return $this->sessionDate->format('Y-m-d') === $day->format('Y-m-d');
    }

    /**
     * @return \DateTimeImmutable when they signed up
     */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
