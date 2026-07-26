<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EventReminderOffset;
use App\Repository\PersonalEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A personal agenda entry owned by a single user (the teacher's own diary): a timed event (a meeting,
 * a tutoring slot) or an all-day reminder. Deliberately separate from {@see Task}: it carries no
 * workflow, role, unit or superior validation — it is nobody's business but its owner's.
 *
 * Privacy is by construction: every query scopes by {@see $owner}, so a superior or admin never sees
 * it through the organisation chart. For the same reason it is intentionally NOT {@see \App\Contract\Auditable}:
 * the audit trail is visible to admins, and a private event's title there would leak it.
 */
#[ORM\Entity(repositoryClass: PersonalEventRepository::class)]
#[ORM\Table(name: 'personal_event')]
// Serves the owner's agenda list and calendar range queries (owner + time window) directly.
#[ORM\Index(name: 'idx_personal_event_owner_start', columns: ['owner_id', 'start_at'])]
// Serves deleting a whole recurring series (owner + series) in one query.
#[ORM\Index(name: 'idx_personal_event_owner_series', columns: ['owner_id', 'series_id'])]
// Serves the reminder sweep, which runs every few minutes across ALL owners: the due ones are the
// narrow slice with a remind_at already past and nothing sent yet.
#[ORM\Index(name: 'idx_personal_event_remind', columns: ['remind_at', 'reminder_sent_at'])]
class PersonalEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The sole owner. Deleting the user takes their private events with them (cascade). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank(message: 'El título es obligatorio.')]
    #[Assert\Length(max: 200)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Start instant. For an all-day entry it is the day at midnight; the time part is not shown. */
    #[ORM\Column(name: 'start_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startAt;

    /** End instant, or null when the entry has no explicit end (a point in time or an all-day entry). */
    #[ORM\Column(name: 'end_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endAt = null;

    /** All-day entry: the times are ignored and only the day is shown. */
    #[ORM\Column(name: 'all_day')]
    private bool $allDay = false;

    /** Colour-coding category (an admin-managed catalogue label), or null for uncategorised. */
    #[ORM\ManyToOne(targetEntity: EventCategory::class)]
    #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?EventCategory $category = null;

    /** Simple personal "done" tick (this is a diary, not a workflow). */
    #[ORM\Column]
    private bool $done = false;

    /**
     * Identifier shared by all occurrences materialised from one recurring entry (e.g. "every Monday
     * until June"), or null for a one-off. Lets the whole series be deleted together while each
     * occurrence stays an ordinary, independently editable event.
     */
    #[ORM\Column(name: 'series_id', length: 32, nullable: true)]
    private ?string $seriesId = null;

    /**
     * How long before the start the owner wants a push reminder, or null for no reminder. Only
     * meaningful on a timed entry: an all-day one starts at midnight, so "10 minutos antes" would fire
     * at 23:50 the night before, which nobody means — {@see setAllDay()} clears it.
     */
    #[ORM\Column(name: 'reminder_minutes', type: Types::INTEGER, nullable: true, enumType: EventReminderOffset::class)]
    private ?EventReminderOffset $reminder = null;

    /**
     * The instant the reminder is due, DERIVED from the start and the offset — never set from outside.
     * Materialising it (instead of computing "start - offset" in the query) keeps the sweep a plain
     * indexed range scan, with no date arithmetic over a column.
     */
    #[ORM\Column(name: 'remind_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $remindAt = null;

    /** When the reminder was actually pushed, or null if still pending. Makes the sweep idempotent. */
    #[ORM\Column(name: 'reminder_sent_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reminderSentAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $owner, string $title, \DateTimeImmutable $startAt)
    {
        $this->owner = $owner;
        $this->title = $title;
        $this->startAt = $startAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    /**
     * Whether this entry belongs to the given user — the single definition of ownership, shared by
     * every access check in the controller.
     *
     * @param User $user the person to check
     *
     * @return bool true if the user owns the entry
     */
    public function isOwnedBy(User $user): bool
    {
        return $this->owner === $user;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getStartAt(): \DateTimeImmutable
    {
        return $this->startAt;
    }

    public function setStartAt(\DateTimeImmutable $startAt): static
    {
        $this->startAt = $startAt;
        $this->recomputeRemindAt();

        return $this;
    }

    public function getEndAt(): ?\DateTimeImmutable
    {
        return $this->endAt;
    }

    public function setEndAt(?\DateTimeImmutable $endAt): static
    {
        $this->endAt = $endAt;

        return $this;
    }

    public function isAllDay(): bool
    {
        return $this->allDay;
    }

    public function setAllDay(bool $allDay): static
    {
        $this->allDay = $allDay;
        // An entry with no time cannot carry a reminder (see $reminder): drop it rather than keep a
        // setting that would never fire, so what the edit form shows back is what actually happens.
        if ($allDay) {
            $this->reminder = null;
        }
        $this->recomputeRemindAt();

        return $this;
    }

    public function getCategory(): ?EventCategory
    {
        return $this->category;
    }

    public function setCategory(?EventCategory $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function isDone(): bool
    {
        return $this->done;
    }

    public function setDone(bool $done): static
    {
        $this->done = $done;

        return $this;
    }

    public function getSeriesId(): ?string
    {
        return $this->seriesId;
    }

    public function setSeriesId(?string $seriesId): static
    {
        $this->seriesId = $seriesId;

        return $this;
    }

    /**
     * Whether this entry is one occurrence of a recurring series (as opposed to a one-off).
     *
     * @return bool true when it belongs to a series
     */
    public function isRecurring(): bool
    {
        return null !== $this->seriesId;
    }

    public function getReminder(): ?EventReminderOffset
    {
        return $this->reminder;
    }

    /**
     * Sets (or clears) the push reminder. Silently ignored on an all-day entry, which by definition has
     * no time to count back from — so the pair (all-day, reminder) can never be stored, whichever order
     * the caller sets them in.
     *
     * @param EventReminderOffset|null $reminder how long before the start to notify, or null for none
     */
    public function setReminder(?EventReminderOffset $reminder): static
    {
        $this->reminder = $this->allDay ? null : $reminder;
        $this->recomputeRemindAt();

        return $this;
    }

    public function getRemindAt(): ?\DateTimeImmutable
    {
        return $this->remindAt;
    }

    public function getReminderSentAt(): ?\DateTimeImmutable
    {
        return $this->reminderSentAt;
    }

    /**
     * Marks the reminder as delivered, so the sweep never pushes it twice.
     *
     * @param \DateTimeImmutable $at when it was sent
     */
    public function markReminderSent(\DateTimeImmutable $at): static
    {
        $this->reminderSentAt = $at;

        return $this;
    }

    /**
     * Recomputes the derived reminder instant after anything it depends on changed (the start or the
     * offset). Re-arms an already-sent reminder ONLY when the instant actually moved, so re-saving an
     * event without touching its schedule does not push the same reminder again.
     */
    private function recomputeRemindAt(): void
    {
        $remindAt = null === $this->reminder
            ? null
            : $this->startAt->modify(\sprintf('-%d minutes', $this->reminder->value));

        // Loose compare: two DateTimeImmutable are equal when they point at the same instant, and it
        // also handles the null/instant transitions.
        if ($remindAt != $this->remindAt) {
            $this->remindAt = $remindAt;
            $this->reminderSentAt = null;
        }
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
