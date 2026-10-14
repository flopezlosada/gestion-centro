<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Contract\AuditRedacted;
use App\Enum\EventReminderOffset;
use App\Enum\MeetingScope;
use App\Enum\MinutesStage;
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
// One generated meeting per group and moment: the backstop behind the sweep's own bookkeeping.
#[ORM\UniqueConstraint(name: 'uniq_meeting_group_start', columns: ['meeting_group_id', 'start_at'])]
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
    #[AuditRedacted]
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
    #[AuditRedacted]
    private ?string $discussion = null;

    /**
     * What was agreed ("acuerdos"). Kept apart from {@see $discussion} because it is the part anybody
     * reads the acta FOR, and the part that has to be findable months later.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[AuditRedacted]
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

    /**
     * The video call to join when it is held online (Meet, Jitsi, Teams…), or null when it is in person.
     * Apart from {@see $place} because it is a link to open, not a room to walk to — and a link typed
     * into the place box could not be tapped. Only http(s) is ever accepted, by the form.
     */
    #[ORM\Column(name: 'online_url', length: 500, nullable: true)]
    private ?string $onlineUrl = null;

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
     * The weekly group this meeting was generated from ({@see \App\Service\RecurringMeetingGenerator}).
     * It is what "the previous meeting" means: the one before this in the same group, whose acta this
     * one approves ({@see \App\Repository\MeetingRepository::findPreviousInGroup()}). Null for a
     * meeting convened by hand.
     */
    #[ORM\ManyToOne(targetEntity: MeetingGroup::class)]
    #[ORM\JoinColumn(name: 'meeting_group_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?MeetingGroup $meetingGroup = null;

    /**
     * The people convened: everybody expected at the meeting, the convener included when they attend it
     * (the form ticks them by default). They see the meeting in their agenda and may read the minutes;
     * they get no say over it (only the convener manages it).
     *
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'meeting_attendee')]
    private Collection $attendees;

    /**
     * Who actually attended, recorded after the meeting. A subset of {@see people()} — enforced by
     * {@see recordSession()}, the only way in — so "asistió alguien que no estaba convocado" is not
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
     * Whether the acta ON FILE no longer says what the acta SAYS: somebody wrote or corrected the
     * desarrollo, los acuerdos or the roll after the PDF was produced.
     *
     * It answers a question the app could not answer before. Correcting a published acta is allowed and
     * expected ("solo para corregir errores"), but the corrected text does not reach the file that was
     * e-mailed to everybody — so the screen has to be able to say so instead of showing two versions and
     * calling both "el acta".
     *
     * **A recorded fact and not a comparison of two timestamps**, which is what it was first. Both stamps
     * live in `DATETIME` columns with one-second resolution, so writing the acta and generating its PDF
     * inside the same second compared as EQUAL and the acta read as up to date when it was not — and
     * loosening the comparison to `>=` would have cried stale on every freshly generated acta instead. The
     * two operations know the answer exactly at the moment they happen ({@see recordSession()} makes it
     * stale, {@see attachMinutes()} makes it fresh); no clock has to be consulted at all.
     */
    #[ORM\Column(name: 'minutes_stale', options: ['default' => false])]
    private bool $minutesStale = false;

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
     * The acta's document in the centre's EducaMadrid cloud, by its file id there — the id and not the
     * path, because it survives the document being renamed or moved, and it is what a link is built from.
     * When it is set, the acta IS that document: its text is written and kept there, never here.
     */
    #[ORM\Column(name: 'minutes_cloud_file_id', length: 32, nullable: true)]
    private ?string $minutesCloudFileId = null;

    /**
     * The PUBLISHED copy of the cloud acta: a frozen copy made by the cloud at each publication and shared
     * to read with the people convened, so what they read is what was published and not the draft that
     * whoever keeps the minutes may still be correcting. A new copy (and a new id) at every publication.
     */
    #[ORM\Column(name: 'minutes_cloud_published_file_id', length: 32, nullable: true)]
    private ?string $minutesCloudPublishedFileId = null;

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

    /**
     * When the acta went out for the FIRST time, or null if it never did. Unlike {@see $minutesPublishedAt}
     * this is never cleared: "esta acta ya salió" is a fact about the past, and replacing the file does not
     * unmake it.
     *
     * It is what the permissions and the observations hang from ({@see wereMinutesEverPublished()}), and the
     * reason is a hole the first version of the correction flow had. Dirección corrected a published acta,
     * regenerated the PDF — which returns it to draft, deliberately — and at that instant lost the very
     * permission it was using: "publicada" had become false, so it could no longer publish the correction.
     * The acta was left as a draft only the (absent) secretary could send, off the archive, with the whole
     * staff still holding the wrong PDF by e-mail. A permission must not depend on a state that the
     * permitted action itself destroys.
     */
    #[ORM\Column(name: 'minutes_first_published_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $minutesFirstPublishedAt = null;

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

    /**
     * Hands the meeting to another convener, as when a weekly group changes who convenes it. Whoever was
     * going to take the minutes because they convened now hands that over too (someone else named on
     * purpose keeps it). The new convener joins the convened list, as a convener does by default, and the
     * previous one leaves it: whether they still come is the group's call ({@see
     * \App\Service\RecurringMeetingGenerator}), which brings them back when they are still a member.
     *
     * @param User $newConvener who convenes from now on
     *
     * @return static the meeting
     */
    public function handOverTo(User $newConvener): static
    {
        if ($this->minutesTakenBy === $this->convener) {
            $this->minutesTakenBy = $newConvener;
        }
        if (null !== $this->convener) {
            $this->attendees->removeElement($this->convener);
        }
        $this->convener = $newConvener;
        $this->addAttendee($newConvener);

        return $this;
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

    /**
     * Sets the agenda. Ignored when the meeting keeps no minutes here, same guard as {@see setType()} and
     * the mirror of what {@see setScope()} clears: the three boxes of the acta only exist for a staff
     * meeting, so writing one into a meeting with families cannot be reached from anywhere.
     */
    public function setAgenda(?string $agenda): static
    {
        if ($this->scope->keepsMinutes()) {
            $this->agenda = $agenda;
        }

        return $this;
    }

    public function getDiscussion(): ?string
    {
        return $this->discussion;
    }

    public function getAgreements(): ?string
    {
        return $this->agreements;
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

    /**
     * Which publication of the acta a page was showing: the publish form carries it, and a publish that
     * arrives with an older one is a repeat — a second press, another tab — of something already done.
     * Empty while unpublished.
     */
    public function minutesPublicationVersion(): string
    {
        return null === $this->minutesPublishedAt ? '' : (string) $this->minutesPublishedAt->getTimestamp();
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
     * Whether this acta has EVER been sent out, even if what is on file right now is a draft again because
     * somebody is correcting it. The predicate that says "el claustro ya tiene un acta de esta reunión", and
     * therefore the one that decides who may correct it ({@see \App\Service\MeetingAccess::canWriteMinutes()})
     * and who may object to it ({@see \App\Service\MeetingAccess::canRemark()}).
     *
     * NOT {@see isMinutesPublished()}, which answers "lo que hay ahora mismo está publicado" and goes back
     * to false the moment the file is replaced — see {@see $minutesFirstPublishedAt} for what that cost.
     *
     * @return bool true once the acta has gone out at least once
     */
    public function wereMinutesEverPublished(): bool
    {
        return null !== $this->minutesFirstPublishedAt;
    }

    public function getMinutesFirstPublishedAt(): ?\DateTimeImmutable
    {
        return $this->minutesFirstPublishedAt;
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
        if (null === $this->minutesPath && null === $this->minutesCloudFileId) {
            return false;
        }

        $this->minutesPublishedAt = new \DateTimeImmutable();
        $this->minutesPublishedBy = $by;
        // Solo la PRIMERA vez: republicar una corrección no reescribe cuándo salió el acta.
        $this->minutesFirstPublishedAt ??= $this->minutesPublishedAt;

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

    /**
     * The link to the video call, when the meeting is held online.
     *
     * @return string|null the URL, or null when it is in person
     */
    public function getOnlineUrl(): ?string
    {
        return $this->onlineUrl;
    }

    /**
     * Sets the link to the video call; blank means it is held in person.
     *
     * @param string|null $onlineUrl the URL, already validated as http(s)
     */
    public function setOnlineUrl(?string $onlineUrl): static
    {
        $this->onlineUrl = null !== $onlineUrl && '' !== trim($onlineUrl) ? trim($onlineUrl) : null;

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

    public function getMeetingGroup(): ?MeetingGroup
    {
        return $this->meetingGroup;
    }

    public function setMeetingGroup(?MeetingGroup $meetingGroup): static
    {
        $this->meetingGroup = $meetingGroup;

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

    /**
     * Takes somebody off the convened list, and off the roll with them: {@see $attended} is a subset of
     * the convened, so a convener who unticks themselves after the roll was taken does not stay on it
     * ("5 de 4 asistieron", and their name in an acta they were not convened to).
     *
     * @param User $attendee the person to take off
     *
     * @return static the meeting
     */
    public function removeAttendee(User $attendee): static
    {
        $this->attendees->removeElement($attendee);
        $this->attended->removeElement($attendee);

        return $this;
    }

    /**
     * Replaces the convened list with exactly the given people, and reports who is NEW — the ones who
     * have to be told. Returning that here (instead of letting each caller diff the collection) keeps
     * "who is new" impossible to get wrong: the notice is sent from the same operation that changed the
     * list.
     *
     * Whoever leaves the list leaves the roll too ({@see removeAttendee()}).
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
                $this->removeAttendee($current);
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
     * Everybody who has to be at the meeting: the convened list, as a plain list. Whoever convenes is on it
     * by default but not by force — the head of studies convenes the tutors of a level and does not always
     * sit in — so this is NOT {@see concerns()}: the convener always concerns the meeting (they run it),
     * but is only expected at it, reminded of it, on its roll and in its acta when convened.
     *
     * @return list<User> the people expected at the meeting
     */
    public function people(): array
    {
        return array_values($this->attendees->toArray());
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
     * Writes the acta: lo tratado, los acuerdos and the roll, in ONE operation.
     *
     * The three used to be two operations behind two endpoints, and the screen showed them as two forms
     * inside one block. Pressing the second button posted only its own fields, so the desarrollo somebody
     * had just typed was dropped on the floor without a word — the browser was doing exactly what two
     * forms mean. There is now one way in, and "media acta guardada" is not representable.
     *
     * **What that does to the roll's timestamp, on purpose.** {@see $attendanceTakenAt} exists so that "no
     * vino nadie" (an empty list, a real answer) does not read as "no se pasó lista". Merging the forms
     * means every save now stamps it, and that is the correct reading and not a side effect: after the
     * merge, saving the acta IS declaring the roll — the list of people is on screen, above the button, and
     * the button says it saves the acta. The state "acta escrita sin lista pasada" stops existing because
     * the roll is part of the acta, not an optional extra step; "no consta la asistencia" is now reserved
     * for an acta nobody ever wrote, which is exactly when it is true.
     *
     * The text is ignored for a meeting that keeps no minutes here (con alumnado o con familias, which go
     * to RAICES) while the roll is still recorded: who turned up to an entrevista con la familia is a fact
     * about this appointment, and it is the only half of the block that screen shows.
     *
     * @param string|null        $discussion what was discussed, or null for nothing recorded
     * @param string|null        $agreements what was agreed, or null for nothing recorded
     * @param list<User>         $present    the people who came
     * @param \DateTimeImmutable $at         when it was written
     *
     * @throws \LogicException when the meeting has not started yet at $at
     */
    public function recordSession(?string $discussion, ?string $agreements, array $present, \DateTimeImmutable $at): static
    {
        $this->assertHeldAt($at);
        if ($this->scope->keepsMinutes()) {
            $this->discussion = $discussion;
            $this->agreements = $agreements;
        }

        return $this->takeRoll($present, $at);
    }

    /**
     * Records ONLY the roll, for an acta whose text is not written here: one kept in the EducaMadrid cloud
     * ({@see hasCloudMinutes()}), where the roll is the one part of the session the app still keeps. With
     * the text written here, the roll goes through {@see recordSession()} instead, so that saving half the
     * acta stays impossible.
     *
     * @param list<User>         $present the people who came
     * @param \DateTimeImmutable $at      when the roll was taken
     *
     * @throws \LogicException when the meeting has not started yet at $at
     */
    public function takeRoll(array $present, \DateTimeImmutable $at): static
    {
        $this->assertHeldAt($at);
        $this->recordAttendance($present, $at);
        // Lo que hay en el disco deja de decir lo que dice el acta. Solo si hay fichero: sin acta generada
        // todavía no hay nada que se haya quedado viejo.
        $this->minutesStale = null !== $this->minutesPath;

        return $this;
    }

    /**
     * Whether the acta ON FILE is older than what the acta SAYS: the text or the roll were written after the
     * PDF was produced.
     *
     * The file is what got published, e-mailed and archived; the text is what the screen shows. Correcting
     * a published acta is allowed by design, so the two can legitimately diverge — and the only wrong
     * answer would be to say nothing and let the centre keep two versions, both called "el acta".
     *
     * @return bool true when the file has to be generated again
     */
    public function minutesOutdated(): bool
    {
        return $this->minutesStale && null !== $this->minutesPath;
    }

    /**
     * Where the acta is in its life ({@see MinutesStage}), read off the fields this meeting already keeps.
     * Null for a meeting that keeps no acta (students, families): there is only a roll there, and a stage
     * would promise a PDF that never comes.
     *
     * The order of the checks is the meaning: a stale file wins over "published", because what the people
     * received no longer says what the acta says, and that is the next thing to fix.
     *
     * @param \DateTimeImmutable $now the instant to read "has it started" against
     *
     * @return MinutesStage|null the stage, or null when the meeting keeps no acta
     */
    public function minutesStage(\DateTimeImmutable $now): ?MinutesStage
    {
        return match (true) {
            !$this->keepsMinutes() => null,
            !$this->isPast($now) => MinutesStage::NOT_STARTED,
            !$this->hasMinutes() => MinutesStage::NO_PDF,
            $this->minutesOutdated() => MinutesStage::OUTDATED,
            !$this->isMinutesPublished() => MinutesStage::DRAFT,
            $this->minutesApprovalRequired && $this->areMinutesApproved() => MinutesStage::APPROVED,
            default => MinutesStage::PUBLISHED,
        };
    }

    /**
     * Records who attended, from the people expected ({@see people()}); anybody else in the list is
     * ignored, so "asistió quien no estaba convocado" cannot be stored whatever the form posts.
     *
     * Private: the roll only ever changes through {@see takeRoll()}, on its own only for an acta written in
     * the cloud and otherwise as part of writing the acta ({@see recordSession()}).
     *
     * @param list<User>         $present the people who came
     * @param \DateTimeImmutable $at      when the roll was taken
     */
    private function recordAttendance(array $present, \DateTimeImmutable $at): static
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
     * Whether the meeting has an acta document: a file here, or a document in the EducaMadrid cloud
     * ({@see hasCloudMinutes()}). Both count for everything that asks "is there an acta" — its stage,
     * approving it, the lists — so an acta in the cloud is never read as "sin acta".
     *
     * @return bool true when this meeting has minutes
     */
    public function hasMinutes(): bool
    {
        return null !== $this->minutesPath || null !== $this->minutesCloudFileId;
    }

    /**
     * Whether the acta is a document in the EducaMadrid cloud ({@see $minutesCloudFileId}).
     */
    public function hasCloudMinutes(): bool
    {
        return null !== $this->minutesCloudFileId;
    }

    /**
     * Whether this meeting's acta is written in the EducaMadrid cloud: it already is there, or the cloud is
     * connected and nothing of the acta has been written HERE yet — neither a file nor a word of text. An
     * acta started in the app keeps its own way when the cloud gets connected halfway: switching it would
     * hide what was already written.
     *
     * @param bool $cloudConnected whether the centre's cloud account is connected
     *
     * @return bool true when the acta goes, or already went, to the cloud
     */
    public function writesMinutesInCloud(bool $cloudConnected): bool
    {
        if (!$this->keepsMinutes()) {
            return false;
        }

        return $this->hasCloudMinutes()
            || ($cloudConnected && null === $this->minutesPath && null === $this->discussion && null === $this->agreements);
    }

    public function getMinutesCloudFileId(): ?string
    {
        return $this->minutesCloudFileId;
    }

    public function getMinutesCloudPublishedFileId(): ?string
    {
        return $this->minutesCloudPublishedFileId;
    }

    /**
     * Publishes the cloud acta with the frozen copy just made of it ({@see $minutesCloudPublishedFileId}):
     * the copy and the publication go together, so a published cloud acta always has what to read.
     *
     * @param string $publishedFileId the copy's id in the cloud
     * @param User   $by              who publishes it
     *
     * @throws \LogicException when the meeting has no cloud acta
     */
    public function publishCloudMinutes(string $publishedFileId, User $by): void
    {
        if (null === $this->minutesCloudFileId) {
            throw new \LogicException('Esta reunión no tiene acta en la nube.');
        }

        $this->minutesCloudPublishedFileId = $publishedFileId;
        $this->publishMinutes($by);
    }

    /**
     * Moves an acta written in the app to the cloud: its documents there replace the text and the file
     * kept here, which are cleared, while its LIFE stays as it was — who wrote it and when, when it was
     * published and approved. Moving is not publishing again: the people convened already received it.
     *
     * @param string      $fileId          the draft's id in the cloud
     * @param string|null $publishedFileId the id of what was published, for an acta that was
     *
     * @return string|null the file kept here that the caller now has to delete, or null if there was none
     *
     * @throws \LogicException when the acta is already in the cloud, or a published acta has no copy
     */
    public function moveMinutesToCloud(string $fileId, ?string $publishedFileId): ?string
    {
        if (null !== $this->minutesCloudFileId) {
            throw new \LogicException('El acta de esta reunión ya está en la nube.');
        }
        if (null !== $this->minutesPublishedAt && null === $publishedFileId) {
            throw new \LogicException('Un acta publicada necesita su copia publicada en la nube.');
        }

        $removed = $this->minutesPath;
        $this->minutesCloudFileId = $fileId;
        $this->minutesCloudPublishedFileId = $publishedFileId;
        $this->discussion = null;
        $this->agreements = null;
        $this->minutesPath = null;
        $this->minutesName = null;
        $this->minutesStale = false;

        return $removed;
    }

    /**
     * Records the acta's document in the cloud. Leaves it a draft, like {@see attachMinutes()}: creating
     * the document is the start of writing it, not sending it.
     *
     * @param string             $fileId the document's id in the cloud
     * @param User               $by     who created it
     * @param \DateTimeImmutable $at     when
     *
     * @throws \LogicException when the meeting has not started yet at $at
     */
    public function attachCloudMinutes(string $fileId, User $by, \DateTimeImmutable $at): void
    {
        $this->assertHeldAt($at);

        $this->minutesCloudFileId = $fileId;
        $this->minutesCloudPublishedFileId = null;
        $this->minutesUploadedBy = $by;
        $this->minutesUploadedAt = $at;
        $this->minutesPublishedAt = null;
        $this->minutesPublishedBy = null;
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
     *
     * @throws \LogicException when the meeting has not started yet at $at
     */
    public function attachMinutes(string $path, string $name, User $by, \DateTimeImmutable $at): ?string
    {
        $this->assertHeldAt($at);
        $replaced = $this->minutesPath;

        $this->minutesPath = $path;
        $this->minutesName = $name;
        $this->minutesUploadedBy = $by;
        $this->minutesUploadedAt = $at;
        // El fichero acaba de salir de lo que dice el acta, así que ya no está desfasado.
        $this->minutesStale = false;
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
        // Sin fichero no hay nada desfasado que avisar: el aviso volvería solo al generar uno nuevo, y este
        // ya nace al día.
        $this->minutesStale = false;
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

    /**
     * Guards the two ways an acta gets content ({@see recordSession()} and {@see attachMinutes()}): an acta
     * records a session that has happened, so neither may run before the meeting starts. Publishing is not
     * guarded here: it needs a file that already passed this check, and the one way left to publish early
     * (moving the meeting to a later date after attaching) is refused by the controller.
     *
     * @param \DateTimeImmutable $at when the acta is being written
     *
     * @throws \LogicException when the meeting has not started yet at $at
     */
    private function assertHeldAt(\DateTimeImmutable $at): void
    {
        if (!$this->isPast($at)) {
            throw new \LogicException('El acta de una reunión solo se escribe una vez empezada la reunión.');
        }
    }
}
