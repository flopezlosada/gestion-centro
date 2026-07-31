<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Enum\EventReminderOffset;
use App\Enum\MeetingScope;
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

    /**
     * What was actually discussed, written in the app after the meeting ("desarrollo de la reunión").
     * Together with {@see $agenda} and {@see $agreements} it is the RAW MATERIAL of the acta, from which
     * (plus the roll) the app generates the PDF.
     *
     * It used to be ONE field holding everything, on the grounds that an acta is prose. The centre then
     * asked for the three boxes by name — "orden del día, desarrollo de la reunión y acuerdos" — which is
     * how their own acta template is laid out, so they are three.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $discussion = null;

    /**
     * What was agreed ("acuerdos"). Kept apart from {@see $discussion} because it is the part anybody
     * reads the acta FOR, and the part that has to be findable months later.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $agreements = null;

    /**
     * Who the meeting is with. It decides whether it keeps minutes at all: a meeting with families or
     * students is recorded in RAICES, so here it is only an appointment ({@see MeetingScope}).
     */
    #[ORM\Column(length: 20, enumType: MeetingScope::class, options: ['default' => 'staff'])]
    private MeetingScope $scope = MeetingScope::STAFF;

    /**
     * What kind of staff meeting it is (CCP, tutores, ED, AMPA…), from the catalogue the centre keeps.
     * Null for a meeting that is none of them, and always null for a meeting that is not with staff.
     */
    #[ORM\ManyToOne(targetEntity: MeetingType::class)]
    #[ORM\JoinColumn(name: 'meeting_type_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?MeetingType $type = null;

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
     * Who actually attended, recorded after the meeting. A subset of {@see people()} — enforced by
     * {@see recordAttendance()}, the only way in — so "asistió alguien que no estaba convocado" is not
     * representable. Empty is ambiguous on its own, which is why {@see $attendanceTakenAt} exists: no
     * timestamp means nobody took the roll, not that nobody came.
     *
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'meeting_attendance')]
    private Collection $attended;

    /** When the roll was taken, or null while nobody has. Distinguishes "nadie vino" from "no se pasó lista". */
    #[ORM\Column(name: 'attendance_taken_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $attendanceTakenAt = null;

    /**
     * Who keeps the minutes ("quien levanta el acta"), which the centre says is NOT always whoever
     * convened: in a collegiate body (CCP, claustro) it is the secretary, in a department meeting the
     * jefatura, in a project one the coordination or the leadership team. So it is a field, not a
     * derivation — the app has no way to know which body a meeting belongs to.
     *
     * Defaults to the convener in the constructor; nullable only so removing a person does not take the
     * meeting with them ({@see minutesKeeper()} falls back to the convener then).
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'minutes_taken_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $minutesTakenBy;

    /**
     * Whether this meeting's acta has to be approved (read and approved at the following meeting). True
     * for a CCP or a department meeting, false for the rest — per the centre. A flag per meeting rather
     * than derived from a "kind of meeting" enum: the app does not know what a CCP is, and inventing four
     * kinds only to feed one default would be modelling for its own sake.
     */
    #[ORM\Column(name: 'minutes_approval_required', options: ['default' => false])]
    private bool $minutesApprovalRequired = false;

    /** When the acta was approved, or null while it is not (or does not need to be). */
    #[ORM\Column(name: 'minutes_approved_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $minutesApprovedAt = null;

    /** Who recorded the approval (a historical fact). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'minutes_approved_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $minutesApprovedBy = null;

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

    /**
     * When the acta was PUBLISHED, or null while it is still a draft.
     *
     * The centre asked for the two steps by name ("hay que dar opción de guardar y de publicar"), and the
     * distinction is real: a generated PDF is a draft that the person keeping the minutes re-reads and
     * regenerates two or three times, and telling everybody about each of those drafts is how people
     * learn to ignore the notice. Publishing is the single, deliberate act that sends the acta out and
     * puts it in everyone's archive.
     */
    #[ORM\Column(name: 'minutes_published_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $minutesPublishedAt = null;

    /** Who published it (a historical fact). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'minutes_published_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $minutesPublishedBy = null;

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
        $this->attended = new ArrayCollection();
        // Quien convoca levanta el acta salvo que se diga otra cosa: así una reunión recién creada nunca
        // está sin nadie a cargo del acta.
        $this->minutesTakenBy = $convener;
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

    public function getDiscussion(): ?string
    {
        return $this->discussion;
    }

    public function setDiscussion(?string $discussion): static
    {
        $this->discussion = $discussion;

        return $this;
    }

    public function getAgreements(): ?string
    {
        return $this->agreements;
    }

    public function setAgreements(?string $agreements): static
    {
        $this->agreements = $agreements;

        return $this;
    }

    public function getScope(): MeetingScope
    {
        return $this->scope;
    }

    /**
     * Sets who the meeting is with. Moving it AWAY from staff drops the kind and everything the acta is
     * made of: a meeting with families keeps no minutes here (it goes into RAICES), so leaving an agenda
     * and some agreements hanging off it would be a half-acta nobody would ever finish.
     */
    public function setScope(MeetingScope $scope): static
    {
        // Con un acta ya adjunta el ámbito se queda como está. La alternativa —dejarlo cambiar— abría un
        // agujero: la ficha escondía el bloque del acta (porque la reunión ya "no lleva acta") pero el
        // fichero seguía publicado, descargable y listado en el archivo. Y la otra alternativa —borrar el
        // acta al cambiar un desplegable— es peor todavía. Quien de verdad se equivocó de ámbito puede
        // quitar el acta primero, que es un gesto explícito y avisa de lo que hace.
        if ($this->hasMinutes() && !$scope->keepsMinutes()) {
            return $this;
        }

        $this->scope = $scope;

        if (!$scope->keepsMinutes()) {
            $this->type = null;
            $this->agenda = null;
            $this->discussion = null;
            $this->agreements = null;
        }

        return $this;
    }

    public function getType(): ?MeetingType
    {
        return $this->type;
    }

    public function setType(?MeetingType $type): static
    {
        // Un tipo solo tiene sentido en una reunión de equipo docente: los tipos SON los del claustro.
        $this->type = $this->scope->keepsMinutes() ? $type : null;

        return $this;
    }

    /**
     * Whether this meeting keeps minutes in the application at all ({@see MeetingScope::keepsMinutes()}).
     * The single predicate the screens ask, instead of comparing the scope by hand in six templates.
     */
    public function keepsMinutes(): bool
    {
        return $this->scope->keepsMinutes();
    }

    public function getMinutesPublishedAt(): ?\DateTimeImmutable
    {
        return $this->minutesPublishedAt;
    }

    public function getMinutesPublishedBy(): ?User
    {
        return $this->minutesPublishedBy;
    }

    /**
     * Whether the acta is published — visible in everyone's archive and already sent out — as opposed to
     * a draft that only whoever keeps the minutes can see.
     */
    public function isMinutesPublished(): bool
    {
        return null !== $this->minutesPublishedAt;
    }

    /**
     * Publishes the acta. Refuses to do it without a file, because publishing is precisely "esto ya es el
     * acta": there has to be an acta.
     *
     * @param User $by who publishes it
     *
     * @return bool true when it was published, false when there is no file to publish
     */
    public function publishMinutes(User $by): bool
    {
        if (null === $this->minutesPath) {
            return false;
        }

        $this->minutesPublishedAt = new \DateTimeImmutable();
        $this->minutesPublishedBy = $by;

        return true;
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

    public function getMinutesTakenBy(): ?User
    {
        return $this->minutesTakenBy;
    }

    public function setMinutesTakenBy(?User $minutesTakenBy): static
    {
        $this->minutesTakenBy = $minutesTakenBy;

        return $this;
    }

    /**
     * Who is on the hook for the acta right now: whoever was named, or the convener when nobody is (the
     * person named was removed from the centre). The single definition used by the access check and by the
     * detail screen, so what the app offers and what it accepts cannot drift.
     *
     * @return User|null the minutes keeper, or null when even the convener is gone
     */
    public function minutesKeeper(): ?User
    {
        return $this->minutesTakenBy ?? $this->convener;
    }

    public function minutesApprovalRequired(): bool
    {
        return $this->minutesApprovalRequired;
    }

    public function setMinutesApprovalRequired(bool $required): static
    {
        $this->minutesApprovalRequired = $required;

        return $this;
    }

    public function getMinutesApprovedAt(): ?\DateTimeImmutable
    {
        return $this->minutesApprovedAt;
    }

    public function getMinutesApprovedBy(): ?User
    {
        return $this->minutesApprovedBy;
    }

    /**
     * Whether the acta is approved. Only meaningful when {@see minutesApprovalRequired()} is true; for a
     * meeting whose acta needs no approval this stays false forever and nothing reads it.
     *
     * @return bool true once the approval was recorded
     */
    public function areMinutesApproved(): bool
    {
        return null !== $this->minutesApprovedAt;
    }

    /**
     * Records that the body approved the acta (typically at the following meeting).
     *
     * @param User               $by who recorded it
     * @param \DateTimeImmutable $at when
     */
    public function approveMinutes(User $by, \DateTimeImmutable $at): static
    {
        $this->minutesApprovedBy = $by;
        $this->minutesApprovedAt = $at;

        return $this;
    }

    /**
     * @return Collection<int, User> the people who actually attended
     */
    public function getAttended(): Collection
    {
        return $this->attended;
    }

    public function getAttendanceTakenAt(): ?\DateTimeImmutable
    {
        return $this->attendanceTakenAt;
    }

    /**
     * Whether the roll has been taken. Reading the timestamp and not the list: an empty list is a legitimate
     * answer ("no vino nadie") and must not read as "sin datos".
     *
     * @return bool true once somebody recorded who attended
     */
    public function isAttendanceTaken(): bool
    {
        return null !== $this->attendanceTakenAt;
    }

    /**
     * Records who attended, from the people expected ({@see people()}); anybody else in the list is
     * ignored, so "asistió quien no estaba convocado" cannot be stored whatever the form posts.
     *
     * @param list<User>         $present the people who came
     * @param \DateTimeImmutable $at      when the roll was taken
     */
    public function recordAttendance(array $present, \DateTimeImmutable $at): static
    {
        // Quien de verdad se apunta: los esperados que estén en la lista. Se recorre `people()` y no
        // `$present`, así el orden guardado es el de la convocatoria y no el que traiga el formulario.
        $keep = array_values(array_filter(
            $this->people(),
            static fn (User $person): bool => \in_array($person, $present, true),
        ));

        // Se quita uno a uno lo que ya no está y se añade lo que falta, en vez de vaciar con clear() y
        // volver a añadir. Sobre una colección PEREZOSA, clear() hace que Doctrine programe el borrado
        // ENTERO de la tabla de unión y los add() del mismo flush se pierden: comprobado en el barrido de
        // esta rama — se guardaba la marca de "lista pasada" y la asistencia quedaba vacía. Recorrer
        // toArray() la inicializa, que es lo que la mantiene en el camino normal de cambios sucios.
        foreach ($this->attended->toArray() as $current) {
            if (!\in_array($current, $keep, true)) {
                $this->attended->removeElement($current);
            }
        }
        foreach ($keep as $person) {
            if (!$this->attended->contains($person)) {
                $this->attended->add($person);
            }
        }

        $this->attendanceTakenAt = $at;

        return $this;
    }

    /**
     * Who was expected and did not come. Derived instead of stored, so it can never contradict the
     * attendance list.
     *
     * @return list<User> the absentees
     */
    public function absentees(): array
    {
        return array_values(array_filter(
            $this->people(),
            fn (User $person): bool => !$this->attended->contains($person),
        ));
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
        // Un acta nueva vuelve a ser borrador: lo que se publicó ya no es lo que hay, y dejar la marca de
        // publicada haría creer al claustro que lo que tienen por correo es esto.
        $this->minutesPublishedAt = null;
        $this->minutesPublishedBy = null;

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
        $this->minutesPublishedAt = null;
        $this->minutesPublishedBy = null;

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
