<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Enum\EventReminderOffset;
use App\Repository\MeetingRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A meeting convened at the centre: a department meeting, a project meeting, a CCP… It has a MOMENT
 * (day and time), a place, an agenda ("orden del día"), the people convened, and — afterwards — its
 * minutes ("el acta").
 *
 * Deliberately neither a {@see Task} nor a {@see PersonalEvent}, because it is neither:
 *  - a task has a DEADLINE and exactly ONE person on the hook, with a workflow of delivery and
 *    validation; a meeting is an appointment with N people and nothing to validate;
 *  - a personal event is PRIVATE by construction (one owner, every query scoped by them), which is the
 *    opposite of convening other people.
 *
 * The minutes ARE stored here (the file, not just a link), unlike a task's deliverable: an acta is the
 * institutional record of what was agreed and the centre asked to keep it in the app. It lives in
 * private storage ({@see \App\Service\FileUploader}) and is served only to the people the meeting
 * concerns ({@see concerns()}) — never publicly.
 */
#[ORM\Entity(repositoryClass: MeetingRepository::class)]
#[ORM\Table(name: 'meeting')]
// Serves the "meetings in a time window" queries (the agenda, the calendar, the list) directly.
#[ORM\Index(name: 'idx_meeting_start', columns: ['start_at'])]
// Serves the reminder sweep, which runs every few minutes across EVERY meeting: the due ones are the
// narrow slice with a remind_at already past and nothing sent yet.
#[ORM\Index(name: 'idx_meeting_remind', columns: ['remind_at', 'reminder_sent_at'])]
class Meeting implements Auditable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Who convened it — the person who may edit it and upload its minutes ({@see
     * \App\Service\MeetingAccess}). Nullable ONLY so removing a person does not take their meetings (and
     * their minutes) with them, same idiom as {@see Task::getCreatedBy()}: the constructor always
     * demands one.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'convener_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $convener;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank(message: 'El título es obligatorio.')]
    #[Assert\Length(max: 200)]
    private string $title;

    /** The agenda ("orden del día"): what is going to be discussed. Free text, optional. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $agenda = null;

    /** Where it takes place ("sala de profesores", "aula 12", "videollamada"). Optional. */
    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $place = null;

    /** When it starts (day and time: a meeting is a moment, never a deadline). */
    #[ORM\Column(name: 'start_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startAt;

    /** When it is expected to end, or null when left open. */
    #[ORM\Column(name: 'end_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endAt = null;

    /**
     * The project this meeting belongs to, when it is a project meeting: it is what makes the
     * coordinator able to convene it, and it groups the project's minutes. Null for any other meeting
     * (a department one, a CCP…), which hangs from nothing but its convener. A department is NOT
     * modelled here on purpose: a meeting is defined by who is convened, not by an org unit.
     */
    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Project $project = null;

    /**
     * The people convened. They see the meeting in their agenda and may read the minutes; they get no
     * say over it (only the convener manages it).
     *
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'meeting_attendee')]
    private Collection $attendees;

    /**
     * Storage-relative path of the minutes file, as returned by {@see \App\Service\FileUploader}. Always
     * set together with {@see $minutesName}, {@see $minutesUploadedAt} and {@see $minutesUploadedBy}
     * through {@see attachMinutes()}: a path without a name (an undownloadable file) is not
     * representable from outside.
     */
    #[ORM\Column(name: 'minutes_path', length: 255, nullable: true)]
    private ?string $minutesPath = null;

    /** Original client filename of the minutes, so the download is served with a meaningful name. */
    #[ORM\Column(name: 'minutes_name', length: 255, nullable: true)]
    private ?string $minutesName = null;

    /** When the minutes were uploaded, or null while there are none. */
    #[ORM\Column(name: 'minutes_uploaded_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $minutesUploadedAt = null;

    /** Who uploaded the minutes (a historical fact, kept even if they later stop coordinating). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'minutes_uploaded_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $minutesUploadedBy = null;

    /**
     * How long before the start everyone convened gets a push reminder, or null for none. Chosen by the
     * convener (not per person): they are the one who knows whether this is a "sal ya" ten minutes or a
     * claustro you have to prepare a day ahead.
     */
    #[ORM\Column(name: 'reminder_minutes', type: Types::INTEGER, nullable: true, enumType: EventReminderOffset::class)]
    private ?EventReminderOffset $reminder = null;

    /**
     * The instant the reminder is due, DERIVED from the start and the offset — never set from outside.
     * Materialised (instead of computing "start - offset" in the query) so the sweep, which runs every few
     * minutes over the whole table, stays an indexed range scan. Same idiom as {@see PersonalEvent}.
     */
    #[ORM\Column(name: 'remind_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $remindAt = null;

    /**
     * When the reminder was pushed, or null if still pending. ONE flag for the whole meeting, not one per
     * attendee: the sweep notifies everybody convened in the same pass, so a single mark is what makes it
     * idempotent.
     */
    #[ORM\Column(name: 'reminder_sent_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reminderSentAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $convener, string $title, \DateTimeImmutable $startAt)
    {
        $this->convener = $convener;
        $this->title = $title;
        $this->startAt = $startAt;
        $this->attendees = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConvener(): ?User
    {
        return $this->convener;
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

    public function getAgenda(): ?string
    {
        return $this->agenda;
    }

    public function setAgenda(?string $agenda): static
    {
        $this->agenda = $agenda;

        return $this;
    }

    public function getPlace(): ?string
    {
        return $this->place;
    }

    public function setPlace(?string $place): static
    {
        $this->place = $place;

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

    public function getReminder(): ?EventReminderOffset
    {
        return $this->reminder;
    }

    /**
     * Sets (or clears) the push reminder for everybody convened.
     *
     * @param EventReminderOffset|null $reminder how long before the start to notify, or null for none
     */
    public function setReminder(?EventReminderOffset $reminder): static
    {
        $this->reminder = $reminder;
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
     * offset). Re-arms an already-sent reminder ONLY when the instant actually moved, so re-saving a
     * meeting after editing just its agenda does not push the same reminder to everybody again — the edit
     * form always rewrites the schedule, even when nothing about it changed.
     */
    private function recomputeRemindAt(): void
    {
        $remindAt = null === $this->reminder
            ? null
            : $this->startAt->modify(\sprintf('-%d minutes', $this->reminder->value));

        // Loose compare: two DateTimeImmutable are equal when they point at the same instant, and it also
        // handles the null/instant transitions.
        if ($remindAt != $this->remindAt) {
            $this->remindAt = $remindAt;
            $this->reminderSentAt = null;
        }
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

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): static
    {
        $this->project = $project;

        return $this;
    }

    /**
     * @return Collection<int, User> the people convened
     */
    public function getAttendees(): Collection
    {
        return $this->attendees;
    }

    public function addAttendee(User $attendee): static
    {
        if (!$this->attendees->contains($attendee)) {
            $this->attendees->add($attendee);
        }

        return $this;
    }

    public function removeAttendee(User $attendee): static
    {
        $this->attendees->removeElement($attendee);

        return $this;
    }

    /**
     * Replaces the convened list with exactly the given people, and reports who is NEW — the ones who
     * have to be told. Returning that here (instead of letting each caller diff the collection) keeps
     * "who is new" impossible to get wrong: the notice is sent from the same operation that changed the
     * list.
     *
     * @param list<User> $people the full list of people who must end up convened
     *
     * @return list<User> the people who were not convened before this call
     */
    public function syncAttendees(array $people): array
    {
        $added = [];
        foreach ($people as $person) {
            if (!$this->attendees->contains($person)) {
                $added[] = $person;
            }
        }

        foreach ($this->attendees->toArray() as $current) {
            if (!\in_array($current, $people, true)) {
                $this->attendees->removeElement($current);
            }
        }
        foreach ($added as $person) {
            $this->attendees->add($person);
        }

        return $added;
    }

    /**
     * Whether the given person is convened to this meeting.
     *
     * @param User $user the person to check
     *
     * @return bool true if the user is on the convened list
     */
    public function isAttendee(User $user): bool
    {
        return $this->attendees->contains($user);
    }

    /**
     * Whether the meeting is any of the given person's business: they convened it or they are convened.
     * The SINGLE definition of that, shared by the visibility gate, the agenda query and the minutes
     * download — so what you can see, what lands in your agenda and what you can read can never
     * disagree.
     *
     * @param User $user the person to check
     *
     * @return bool true if the meeting concerns the user
     */
    public function concerns(User $user): bool
    {
        return $this->convener === $user || $this->isAttendee($user);
    }

    /**
     * Everybody who has to be at the meeting: whoever convened it plus the convened, deduplicated. Unlike
     * {@see concerns()} (a predicate about one person), this is the LIST — and it is what the reminder
     * notifies: the convener has to turn up too, so leaving them out of a "empieza en 10 minutos" would be
     * the one notice they actually need.
     *
     * @return list<User> the people expected at the meeting
     */
    public function people(): array
    {
        $people = $this->attendees->toArray();
        if (null !== $this->convener && !\in_array($this->convener, $people, true)) {
            $people[] = $this->convener;
        }

        return array_values($people);
    }

    public function getMinutesPath(): ?string
    {
        return $this->minutesPath;
    }

    public function getMinutesName(): ?string
    {
        return $this->minutesName;
    }

    public function getMinutesUploadedAt(): ?\DateTimeImmutable
    {
        return $this->minutesUploadedAt;
    }

    public function getMinutesUploadedBy(): ?User
    {
        return $this->minutesUploadedBy;
    }

    /**
     * Whether the minutes have been uploaded. Reads the path, which is the only thing that makes the
     * download possible.
     *
     * @return bool true when this meeting has minutes
     */
    public function hasMinutes(): bool
    {
        return null !== $this->minutesPath;
    }

    /**
     * Attaches the minutes, recording who uploaded them and when, and returns the path of the file it
     * REPLACED (null if there was none) so the caller can delete it and leave no orphan in storage.
     *
     * The four fields move together on purpose: the state "there is a file but no name / no author" is
     * not reachable from outside, so the download always has something to call the file and the detail
     * always knows who signed it off.
     *
     * @param string             $path storage-relative path returned by the uploader
     * @param string             $name original filename, used when serving the download
     * @param User               $by   who uploaded it
     * @param \DateTimeImmutable $at   when it was uploaded
     *
     * @return string|null the replaced file's path, or null when there was nothing to replace
     */
    public function attachMinutes(string $path, string $name, User $by, \DateTimeImmutable $at): ?string
    {
        $replaced = $this->minutesPath;

        $this->minutesPath = $path;
        $this->minutesName = $name;
        $this->minutesUploadedBy = $by;
        $this->minutesUploadedAt = $at;

        return $replaced;
    }

    /**
     * Removes the minutes and returns the stored path so the caller can delete the file (null when there
     * were none). Clears the four fields together, the counterpart of {@see attachMinutes()}.
     *
     * @return string|null the removed file's path, or null when there were no minutes
     */
    public function clearMinutes(): ?string
    {
        $removed = $this->minutesPath;

        $this->minutesPath = null;
        $this->minutesName = null;
        $this->minutesUploadedBy = null;
        $this->minutesUploadedAt = null;

        return $removed;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Whether the meeting has already happened (its start is in the past). Splits the list into what is
     * coming and the archive of minutes.
     *
     * @param \DateTimeImmutable $now the reference instant
     *
     * @return bool true when the meeting already started
     */
    public function isPast(\DateTimeImmutable $now): bool
    {
        return $this->startAt < $now;
    }
}
