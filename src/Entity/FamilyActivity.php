<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Repository\FamilyActivityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An activity of the centre open to families taking part (interactive groups, workshops, complementary
 * activities…), published by the teacher who organises it.
 *
 * It holds no family data on purpose: who comes is a separate concern, so the activities can be planned
 * and listed before any family has an account.
 */
#[ORM\Entity(repositoryClass: FamilyActivityRepository::class)]
#[ORM\Table(name: 'family_activity')]
// Serves the list, which always reads a window of days in order.
#[ORM\Index(name: 'idx_family_activity_start', columns: ['start_at'])]
class FamilyActivity implements Auditable
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

    #[ORM\Column(name: 'start_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startAt;

    /** End instant on the same day, or null when only the start is known. */
    #[ORM\Column(name: 'end_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endAt = null;

    /** How many families the organiser would like, or null when any number helps. */
    #[ORM\Column(name: 'families_wanted', type: Types::SMALLINT, nullable: true)]
    #[Assert\Positive(message: 'Pon un número mayor que cero, o déjalo vacío.')]
    #[Assert\LessThanOrEqual(100, message: 'Como mucho {{ compared_value }} familias.')]
    private ?int $familiesWanted = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param User               $organizer who publishes it
     * @param string             $title     what it is
     * @param \DateTimeImmutable $startAt   when it starts
     */
    public function __construct(User $organizer, string $title, \DateTimeImmutable $startAt)
    {
        $this->organizer = $organizer;
        $this->title = $title;
        $this->startAt = $startAt;
        $this->createdAt = new \DateTimeImmutable();
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
     * Sets both ends at once, so an end before the start can never be stored.
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
}
