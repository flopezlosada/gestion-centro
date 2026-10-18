<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Family\FamilySession;
use App\Repository\FamilyActivityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An activity of the centre open to families taking part (interactive groups, workshops, complementary
 * activities…), published by the teacher who organises it. Of one of two kinds, each its own type so
 * neither can carry the other's "when": a one-off on a day and time of its own
 * ({@see OneOffFamilyActivity}), or one of the teacher's classes every week ({@see WeeklyFamilyActivity}).
 *
 * Families come to SESSIONS ({@see FamilySession}): the one-off has one, the weekly one has one each
 * week its class is given. Who comes is kept apart, in {@see FamilySignup}, one per family and session,
 * changed only through {@see signUp()} and {@see withdraw()} — which take the session, so the rules
 * (not started, not full) are checked against the very date being asked for.
 */
#[ORM\Entity(repositoryClass: FamilyActivityRepository::class)]
#[ORM\Table(name: 'family_activity')]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'kind', type: 'string', length: 16)]
#[ORM\DiscriminatorMap(['one_off' => OneOffFamilyActivity::class, 'weekly' => WeeklyFamilyActivity::class])]
// Serves the calendar, which reads the one-offs of a week in order.
#[ORM\Index(name: 'idx_family_activity_start', columns: ['start_at'])]
abstract class FamilyActivity implements Auditable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Who organises it, and so who may change it. Null once that person is deleted: the activity stays,
     * because the families it was announced to still expect it, and an admin can take it over.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'organizer_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $organizer;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank(message: 'El título es obligatorio.')]
    #[Assert\Length(max: 200)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 5000)]
    private ?string $description = null;

    /** Where it takes place, free text: it may be a classroom, the library or the playground. */
    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $place = null;

    /** How many families the organiser would like, or null when any number helps. */
    #[ORM\Column(name: 'families_wanted', type: Types::SMALLINT, nullable: true)]
    #[Assert\Positive(message: 'Pon un número mayor que cero, o déjalo vacío.')]
    #[Assert\LessThanOrEqual(100, message: 'Como mucho {{ compared_value }} familias.')]
    private ?int $familiesWanted = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * The families coming, to every session. Removing one from here deletes it (orphanRemoval); removing
     * the activity deletes them all in the database (ON DELETE CASCADE).
     *
     * @var Collection<int, FamilySignup>
     */
    #[ORM\OneToMany(targetEntity: FamilySignup::class, mappedBy: 'activity', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $signups;

    /**
     * @param User   $organizer who publishes it
     * @param string $title     what it is
     */
    public function __construct(User $organizer, string $title)
    {
        $this->organizer = $organizer;
        $this->title = $title;
        $this->createdAt = new \DateTimeImmutable();
        $this->signups = new ArrayCollection();
    }

    /**
     * @return int|null the id, null until persisted
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return User|null who organises it, or null if that person is no longer in the app
     */
    public function getOrganizer(): ?User
    {
        return $this->organizer;
    }

    /**
     * Whether the given person organises it — the single definition of ownership for every check.
     *
     * @param User $user the person to check
     *
     * @return bool true if they organise it
     */
    public function isOrganizedBy(User $user): bool
    {
        return $this->organizer === $user;
    }

    /**
     * @return string what it is
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * @param string $title what it is
     */
    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    /**
     * @return string|null what families should know, or null
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * @param string|null $description what families should know, or null
     */
    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @return string|null where it takes place, or null
     */
    public function getPlace(): ?string
    {
        return $this->place;
    }

    /**
     * @param string|null $place where it takes place, or null
     */
    public function setPlace(?string $place): static
    {
        $this->place = $place;

        return $this;
    }

    /**
     * @return int|null how many families the organiser would like, or null when any number helps
     */
    public function getFamiliesWanted(): ?int
    {
        return $this->familiesWanted;
    }

    /**
     * @param int|null $familiesWanted how many families the organiser would like, or null
     */
    public function setFamiliesWanted(?int $familiesWanted): static
    {
        $this->familiesWanted = $familiesWanted;

        return $this;
    }

    /**
     * @return \DateTimeImmutable when it was published
     */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return list<FamilySignup> the families coming, to every session, in the order they signed up
     */
    public function getSignups(): array
    {
        return array_values($this->signups->toArray());
    }

    /**
     * Whether it repeats every week in one of the organiser's classes, rather than happening once.
     */
    abstract public function isWeekly(): bool;

    /**
     * The families coming to one session, in the order they signed up.
     *
     * @param \DateTimeImmutable $day the day of the session
     *
     * @return list<FamilySignup> its signups
     */
    public function signupsOn(\DateTimeImmutable $day): array
    {
        return array_values($this->signups->filter(static fn (FamilySignup $s): bool => $s->isFor($day))->toArray());
    }

    /**
     * Whether the given family has said it will come to that session.
     *
     * @param FamilyVolunteer    $family the family to check
     * @param \DateTimeImmutable $day    the day of the session
     *
     * @return bool true if it is signed up
     */
    public function isSignedUpBy(FamilyVolunteer $family, \DateTimeImmutable $day): bool
    {
        return null !== $this->signupOf($family, $day);
    }

    /**
     * How many more families may come to that session.
     *
     * @param \DateTimeImmutable $day the day of the session
     *
     * @return int|null the places left (0 once full, or fuller after the number was lowered), or null when
     *                  any number helps
     */
    public function placesLeftOn(\DateTimeImmutable $day): ?int
    {
        return null === $this->familiesWanted ? null : max(0, $this->familiesWanted - \count($this->signupsOn($day)));
    }

    /**
     * Signs a family up for one session — the only way a {@see FamilySignup} is created. Signing up twice
     * changes nothing.
     *
     * Two families taking the last place at the same instant may both get it: the count is read without a
     * lock. Accepted: a volunteer more is no harm, and the organiser sees who comes.
     *
     * @param FamilySession      $session the session, of this activity
     * @param FamilyVolunteer    $family  who comes
     * @param \DateTimeImmutable $now     the current instant
     *
     * @return bool whether it was not signed up yet, so something changed
     *
     * @throws \DomainException if it has started or is full
     */
    public function signUp(FamilySession $session, FamilyVolunteer $family, \DateTimeImmutable $now): bool
    {
        $this->assertOwn($session);
        if ($this->isSignedUpBy($family, $session->day)) {
            return false;
        }
        if (!$session->acceptsSignupsAt($now)) {
            throw new \DomainException('The session has started or is full.');
        }
        $this->signups->add(new FamilySignup($this, $family, $session->day, $now));

        return true;
    }

    /**
     * Takes a family off one session. Withdrawing when not signed up changes nothing.
     *
     * @param FamilySession      $session the session, of this activity
     * @param FamilyVolunteer    $family  who no longer comes
     * @param \DateTimeImmutable $now     the current instant
     *
     * @return bool whether it was signed up, so something changed
     *
     * @throws \DomainException if it has started
     */
    public function withdraw(FamilySession $session, FamilyVolunteer $family, \DateTimeImmutable $now): bool
    {
        $this->assertOwn($session);
        if (!$session->isOpenAt($now)) {
            throw new \DomainException('The session has started.');
        }
        $signup = $this->signupOf($family, $session->day);
        if (null === $signup) {
            return false;
        }
        $this->signups->removeElement($signup);

        return true;
    }

    /**
     * Signs a family up for several sessions at once — "every week" of a weekly activity —, each by the
     * rules of {@see signUp()}. A full one is left out rather than refusing the rest: the family still
     * comes the other weeks, and is told which were full.
     *
     * @param list<FamilySession> $sessions the sessions, of this activity, not started
     * @param FamilyVolunteer     $family   who comes
     * @param \DateTimeImmutable  $now      the current instant
     *
     * @return array{added: list<FamilySession>, full: list<FamilySession>} the sessions it was signed up for
     *                                                                       now, and those it could not be
     */
    public function signUpForEach(array $sessions, FamilyVolunteer $family, \DateTimeImmutable $now): array
    {
        $added = [];
        $full = [];
        foreach ($sessions as $session) {
            if ($this->isSignedUpBy($family, $session->day)) {
                continue;
            }
            if ($session->acceptsSignupsAt($now)) {
                $this->signUp($session, $family, $now);
                $added[] = $session;
            } else {
                $full[] = $session;
            }
        }

        return ['added' => $added, 'full' => $full];
    }

    /**
     * Takes a family off several sessions at once — "no more weeks" —, each by the rules of {@see withdraw()}.
     *
     * @param list<FamilySession> $sessions the sessions, of this activity, not started
     * @param FamilyVolunteer     $family   who no longer comes
     * @param \DateTimeImmutable  $now      the current instant
     *
     * @return list<FamilySession> the sessions it was signed up for and no longer is
     */
    public function withdrawFromEach(array $sessions, FamilyVolunteer $family, \DateTimeImmutable $now): array
    {
        return array_values(array_filter($sessions, fn (FamilySession $session): bool => $this->withdraw($session, $family, $now)));
    }

    /**
     * Moves every signup to the session on another day: for a one-off, whose only session is itself.
     *
     * @param \DateTimeImmutable $day the day of the session now
     */
    protected function moveSignupsTo(\DateTimeImmutable $day): void
    {
        foreach ($this->signups as $signup) {
            $signup->moveTo($day);
        }
    }

    /**
     * @param FamilySession $session a session
     *
     * @throws \LogicException if it is another activity's: a bug, not something a family can cause
     */
    private function assertOwn(FamilySession $session): void
    {
        if ($session->activity !== $this) {
            throw new \LogicException('The session belongs to another activity.');
        }
    }

    /**
     * @param FamilyVolunteer    $family the family
     * @param \DateTimeImmutable $day    the day of the session
     *
     * @return FamilySignup|null its signup to that session, or null if it is not coming
     */
    private function signupOf(FamilyVolunteer $family, \DateTimeImmutable $day): ?FamilySignup
    {
        return $this->signups->findFirst(static fn (int $key, FamilySignup $s): bool => $s->getFamily() === $family && $s->isFor($day));
    }
}
