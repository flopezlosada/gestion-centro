<?php

declare(strict_types=1);

namespace App\Entity;

use App\Contract\Auditable;
use App\Enum\DeliverableRequirement;
use App\Enum\TaskType;
use App\Repository\TaskRepository;
use App\Support\TaskStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A concrete task of a given course: either instantiated from a {@see TaskTemplate} or created
 * ad-hoc. Its {@see $status} is the Symfony Workflow marking (the state machine chosen by
 * {@see $type}); progress declared by the assignee and validation by the superior are distinct
 * transitions of that machine.
 *
 * What has to be handed in is {@see $deliverable}: a link ({@see $deliverableReference}, an opaque
 * reference to a document living in the school's cloud), a file ({@see $deliverableFilePath}, kept in
 * private storage), either, or nothing at all.
 */
#[ORM\Entity(repositoryClass: TaskRepository::class)]
#[ORM\Table(name: 'task')]
#[ORM\Index(name: 'idx_task_year_due', columns: ['school_year', 'due_date'])]
// Leads with due_date to serve findOpenDueOn() (deadline + open status) directly.
#[ORM\Index(name: 'idx_task_due_status', columns: ['due_date', 'status'])]
class Task implements Auditable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The template this task was instantiated from, if any (ad-hoc tasks have none). */
    #[ORM\ManyToOne(targetEntity: TaskTemplate::class)]
    #[ORM\JoinColumn(name: 'template_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TaskTemplate $template = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 200)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 30, enumType: TaskType::class)]
    private TaskType $type = TaskType::SIMPLE;

    /** Academic year in canonical "YYYY-YYYY" form (see {@see \App\Util\SchoolYear}). */
    #[ORM\Column(name: 'school_year', length: 9)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^\d{4}-\d{4}$/', message: 'El curso debe tener el formato "AAAA-AAAA".')]
    private string $schoolYear;

    /** Deadline, fixed at instantiation — never inherited from the template. */
    #[ORM\Column(name: 'due_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $dueDate;

    #[ORM\Column]
    private bool $mandatory = true;

    /** Current state-machine place (Symfony Workflow marking). */
    #[ORM\Column(length: 30)]
    private string $status;

    /** The specific person responsible, if assigned to an individual. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'assigned_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $assignedUser = null;

    /** The department this task belongs to (its context for scope and escalation). */
    #[ORM\ManyToOne(targetEntity: Department::class)]
    #[ORM\JoinColumn(name: 'unit_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Department $unit = null;

    /**
     * What structurally makes this task someone's job: a role, plus the department when the role is
     * scoped to one. Resolved live, so the task follows whoever holds the post today. Owned by the task
     * (cascade + orphan removal).
     *
     * The single structural answer since the legacy `assigned_role_id` column went away: a task now
     * either has a responsibility or has nobody, and there is no second, weaker way of being someone's
     * job that half the codebase did not know how to read.
     *
     * Still nullable, because a task genuinely can have no responsible — a post nobody holds, a row
     * imported before the model existed — and {@see resolveResponsible()} says so by returning null
     * rather than by inventing one.
     */
    #[ORM\OneToOne(targetEntity: TaskResponsibility::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\JoinColumn(name: 'responsibility_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TaskResponsibility $responsibility = null;

    /**
     * What has to be handed in to deliver this task: nothing, a link, a file, or either. Chosen by
     * whoever creates it — "el equipo directivo decide si la tarea requiere hipervínculo, archivo
     * adjunto o la posibilidad de entregar cualquiera de las opciones".
     */
    #[ORM\Column(name: 'deliverable_requirement', length: 10, enumType: DeliverableRequirement::class, options: ['default' => 'none'])]
    private DeliverableRequirement $deliverable = DeliverableRequirement::NONE;

    #[ORM\Column]
    private bool $requiresCheckbox = true;

    /** The "done" checkbox declared by the assignee (progress, not validation). */
    #[ORM\Column]
    private bool $checkboxDone = false;

    /** Opaque reference/link to the deliverable in the school's cloud — never the content itself. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $deliverableReference = null;

    /**
     * Storage-relative path of the delivered file, as returned by {@see \App\Service\FileUploader}, or
     * null when nothing was uploaded. Always set together with {@see $deliverableFileName} through
     * {@see attachDeliverableFile()}: a path without a name (an undownloadable file) is not
     * representable from outside.
     *
     * Unlike the link, the file IS kept by the app. That is a deliberate exception to the Fase 1 rule of
     * "references, never content": the centre needs a scanned sheet or a signed form to be somewhere, and
     * the alternative is a teacher e-mailing it and nobody finding it in March. It lives in private
     * storage and is served only to the people the task concerns.
     */
    #[ORM\Column(name: 'deliverable_file_path', length: 255, nullable: true)]
    private ?string $deliverableFilePath = null;

    /** Original client filename of the delivered file, so the download keeps a meaningful name. */
    #[ORM\Column(name: 'deliverable_file_name', length: 255, nullable: true)]
    private ?string $deliverableFileName = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** Who created the task (null for seeded/imported tasks). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    /**
     * Explicit delegation override: a superior may hand the task to a specific subordinate, on top of
     * (not replacing) its structural responsibility, so "who does it now" is this person while the
     * task still knows what it structurally is. Null means no delegation — the responsibility holder
     * does it.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'delegated_to_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $delegatedTo = null;

    /**
     * Who actually did the task, frozen once when it reaches the terminal "validated" state. A
     * historical fact (same idiom as {@see $createdBy}): later changes to the responsibility holder or
     * a unit's manager never rewrite it. Null while the task is still open.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'completed_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $completedBy = null;

    public function __construct(string $title, string $schoolYear, \DateTimeImmutable $dueDate, TaskType $type = TaskType::SIMPLE)
    {
        $this->title = $title;
        $this->schoolYear = $schoolYear;
        $this->dueDate = $dueDate;
        $this->type = $type;
        $this->status = $type->initialPlace();
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * Builds a task instance from a recurring template for a given course. Copies the definition but
     * NOT the date (fixed here, never inherited).
     *
     * The template's role becomes a real {@see TaskResponsibility}, the same backbone an ad-hoc task
     * gets from the form. It used to be written into a separate `assignedRole` column instead, and
     * that made a generated task a second-class citizen of the model: {@see \App\Service\OrganizationHierarchy}
     * only ever reads the responsibility, so a task straight from the catalogue had NOBODY above it —
     * no validator, no escalation — and it did not say so anywhere.
     *
     * A template names a FUNCTION ("jefatura de departamento"), not whose: which department this
     * particular instance is for is decided by {@see \App\Service\TaskGenerator}, which expands a
     * per-department template into one task per department and passes each one here. Null for a
     * centre-wide function (dirección, secretaría), which has exactly one instance.
     *
     * No assignee is set, on purpose. Leaving it null lets the responsibility resolve the person LIVE
     * ({@see resolveResponsible()}), so a task generated in September and a change of jefatura in
     * January need no reconciliation — and a department with no holder reads as "Sin asignar" instead
     * of freezing whoever happened to hold the post the day the course was generated.
     *
     * @param TaskTemplate       $template   the recurring template
     * @param string             $schoolYear the target course in "YYYY-YYYY" form
     * @param \DateTimeImmutable $dueDate     the deadline for this instance
     * @param Department|null    $department the department this instance is for, or null for centre-wide
     *
     * @return self the new task instance
     */
    public static function fromTemplate(TaskTemplate $template, string $schoolYear, \DateTimeImmutable $dueDate, ?Department $department = null): self
    {
        $task = new self($template->getTitle(), $schoolYear, $dueDate, $template->getType());
        $task->template = $template;
        $task->description = $template->getDescription();
        $task->mandatory = $template->isMandatory();
        $role = $template->getResponsibleRole();
        $task->responsibility = null !== $role ? new TaskResponsibility($role, $department) : null;
        // Mirrored onto the task's own unit, like the form does: it is the context the hierarchy and
        // the visibility scope fall back to when there is no responsibility at all.
        $task->unit = $department;
        $task->deliverable = $template->getDeliverable();
        $task->requiresCheckbox = $template->requiresCheckbox();

        return $task;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTemplate(): ?TaskTemplate
    {
        return $this->template;
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

    public function getType(): TaskType
    {
        return $this->type;
    }

    public function getSchoolYear(): string
    {
        return $this->schoolYear;
    }

    public function setSchoolYear(string $schoolYear): static
    {
        $this->schoolYear = $schoolYear;

        return $this;
    }

    public function getDueDate(): \DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function setDueDate(\DateTimeImmutable $dueDate): static
    {
        $this->dueDate = $dueDate;

        return $this;
    }

    public function isMandatory(): bool
    {
        return $this->mandatory;
    }

    public function setMandatory(bool $mandatory): static
    {
        $this->mandatory = $mandatory;

        return $this;
    }

    /**
     * The Workflow marking. Named getStatus/setStatus to match the method marking store in
     * config/packages/workflow.yaml.
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    /**
     * Whether the task is still in its initial place (Pendiente): nada entregado ni cerrado. Es la
     * ventana en la que la tarea admite cambios de titular (delegar): una vez entregada, reasignarla
     * reescribiría quién hizo qué.
     *
     * @return bool true while the task is Pendiente
     */
    public function isPending(): bool
    {
        return TaskStatus::PENDING === $this->status;
    }

    /**
     * Whether the task has been delivered and awaits validation (Entregada). Es la única ventana en la
     * que se corrige el enlace del entregable: antes no hay nada que corregir y después ya está juzgado.
     *
     * @return bool true while the task is Entregada
     */
    public function isSubmitted(): bool
    {
        return TaskStatus::SUBMITTED === $this->status;
    }

    /**
     * Whether the task came back with corrections to make (En revisión). Distinct from Pendiente on
     * purpose: it has already been delivered once and carries, in writing, what has to change.
     *
     * @return bool true while the task is En revisión
     */
    public function isInReview(): bool
    {
        return TaskStatus::IN_REVIEW === $this->status;
    }

    /**
     * Whether the task is closed: finalizada o cancelada ({@see TaskStatus::CLOSED}). Una tarea cerrada
     * es de solo lectura — ni se ejecuta, ni se delega, ni se marca/desmarca.
     *
     * @return bool true if the task reached a terminal place
     */
    public function isClosed(): bool
    {
        return \in_array($this->status, TaskStatus::CLOSED, true);
    }

    /**
     * Whether the task is out of time ("fuera de plazo"): still open and its deadline already past.
     *
     * The single definition, on the entity, because three places asked the same question and each one
     * wrote it again — the controller, the task list and the detail template — and one of them had
     * already drifted. It also decides whether "Cancelar" is offered ({@see \App\Controller\TaskController}):
     * the centre does not want a task that is out of time to be disposed of instead of delivered.
     *
     * Compared as "Y-m-d" strings and never as instants: the deadline is a DAY, and comparing it as a
     * moment makes the answer depend on the server timezone (green in Madrid, wrong in CI's UTC).
     *
     * @param \DateTimeInterface $today the day to measure against (accepts Twig's date(), hence the
     *                                  wider interface)
     *
     * @return bool true when the task is open and its deadline has passed
     */
    public function isOverdueOn(\DateTimeInterface $today): bool
    {
        return !$this->isClosed() && $this->dueDate->format('Y-m-d') < $today->format('Y-m-d');
    }

    /**
     * Whether the task counts as done for the person: su responsable marcó la casilla de progreso o la
     * tarea ya está Finalizada. Fuente ÚNICA del "hecho" que pintan la agenda y el calendario (la usa
     * {@see \App\Agenda\AgendaEntry::fromTask()}), para que una finalizada nunca se vea como pendiente.
     *
     * @return bool true if the task is done
     */
    public function isDone(): bool
    {
        // The progress checkbox only counts when the task actually uses it (a deliverable task does not),
        // so a stale checkbox on a deliverable never masquerades as done — only Finalizada closes it.
        return ($this->requiresCheckbox() && $this->checkboxDone) || TaskStatus::VALIDATED === $this->status;
    }

    /**
     * The role this task is structurally the job of, or null when it has no responsibility. The single
     * place that answers "¿de qué rol es esta tarea?", shared by the list filter, the detail screen and
     * the two workflow subscribers — each of which used to spell out the same two-step fallback, so a
     * fix to one of them would silently have left the others behind.
     *
     * @return Role|null the responsible role, or null
     */
    public function responsibleRole(): ?Role
    {
        return $this->responsibility?->getRole();
    }

    public function getAssignedUser(): ?User
    {
        return $this->assignedUser;
    }

    public function setAssignedUser(?User $assignedUser): static
    {
        $this->assignedUser = $assignedUser;

        return $this;
    }

    /**
     * Whether the task belongs to the given user: assigned to them directly or to a role they hold.
     * The single definition of a task being "theirs", shared by the visibility scope
     * ({@see \App\Service\TaskVisibility}) and the who-may-work-on-it check in the task controller.
     *
     * @param User $user the person to check
     *
     * @return bool true if the task is assigned to the user or to one of their roles
     */
    public function isOwnedBy(User $user): bool
    {
        // A delegation overrides everything: only the delegatee owns it then.
        if (null !== $this->delegatedTo) {
            return $this->delegatedTo === $user;
        }

        // The concrete assignee is authoritative: the person picked in the responsibility cascade (kept
        // current by the ranked-role handover). Only ONE person owns a task, not every role holder.
        if (null !== $this->assignedUser) {
            return $this->assignedUser === $user;
        }

        // No concrete assignee (e.g. a post nobody holds yet, or a task straight from the catalogue):
        // whoever holds the structural responsibility right now. Kept in step with the SQL in
        // {@see \App\Repository\TaskRepository::findAgendaFor()}, which asks the same question of the
        // whole course at once.
        return $this->responsibility?->isHeldBy($user) ?? false;
    }

    /**
     * Whether the task CONCERNS the given user: they hold it right now ({@see isOwnedBy()}) or they are
     * its titular assignee even after delegating it down — delegating hands over the WORK, never the
     * accountability, so the titular keeps the say over their own task (retiring the delegation,
     * judging what the delegatee delivered) and keeps seeing it.
     *
     * The distinction matters because {@see isOwnedBy()} is exclusive on purpose (only ONE person may
     * "do" a task, so a superior cannot execute a subordinate's work). Every check that asks "is this
     * person part of this task" — as opposed to "must this person do it" — belongs here.
     *
     * @param User $user the person to check
     *
     * @return bool true if the user holds the task or is its titular assignee
     */
    public function concerns(User $user): bool
    {
        return $this->isOwnedBy($user) || $this->assignedUser === $user;
    }

    public function getUnit(): ?Department
    {
        return $this->unit;
    }

    public function setUnit(?Department $unit): static
    {
        $this->unit = $unit;

        return $this;
    }

    public function getDeliverable(): DeliverableRequirement
    {
        return $this->deliverable;
    }

    public function setDeliverable(DeliverableRequirement $deliverable): static
    {
        $this->deliverable = $deliverable;

        return $this;
    }

    /**
     * Whether the task cannot be delivered empty-handed. Kept as a predicate over
     * {@see $deliverable} (it used to be its own boolean column) so the many callers that only ask
     * "¿lleva entregable?" — the list, the agenda, the home row — keep reading one single source.
     */
    public function requiresDocument(): bool
    {
        return $this->deliverable->isRequired();
    }

    /**
     * Whether the task has been delivered with something attached: a link, a file, or both.
     *
     * @return bool true when there is anything to open
     */
    public function hasDeliverable(): bool
    {
        return null !== $this->deliverableReference || null !== $this->deliverableFilePath;
    }

    public function getDeliverableFilePath(): ?string
    {
        return $this->deliverableFilePath;
    }

    public function getDeliverableFileName(): ?string
    {
        return $this->deliverableFileName;
    }

    /**
     * Attaches (or, with nulls, drops) the delivered file. Path and name move together on purpose: a
     * path without a name is a file nobody can download, and that state should not be reachable.
     *
     * @param string|null $path the storage-relative path, or null to drop it
     * @param string|null $name the original filename, or null to drop it
     */
    public function attachDeliverableFile(?string $path, ?string $name): static
    {
        $this->deliverableFilePath = null !== $path && '' !== $path ? $path : null;
        $this->deliverableFileName = null !== $this->deliverableFilePath ? ($name ?? 'documento') : null;

        return $this;
    }

    /**
     * Whether this task closes via a one-click progress checkbox. A task with a deliverable NEVER does:
     * it is completed by Entregar (with the document) → Validar, so the two completion paths stay
     * mutually exclusive. Enforced here so a deliverable task can't be closed with the checkbox skipping
     * its document, whatever the stored flags say (fixes existing rows too, no migration).
     */
    public function requiresCheckbox(): bool
    {
        return $this->requiresCheckbox && !$this->requiresDocument();
    }

    public function setRequiresCheckbox(bool $requiresCheckbox): static
    {
        $this->requiresCheckbox = $requiresCheckbox;

        return $this;
    }

    public function isCheckboxDone(): bool
    {
        return $this->checkboxDone;
    }

    public function setCheckboxDone(bool $checkboxDone): static
    {
        $this->checkboxDone = $checkboxDone;

        return $this;
    }

    public function getDeliverableReference(): ?string
    {
        return $this->deliverableReference;
    }

    public function setDeliverableReference(?string $deliverableReference): static
    {
        $this->deliverableReference = $deliverableReference;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getDelegatedTo(): ?User
    {
        return $this->delegatedTo;
    }

    public function setDelegatedTo(?User $delegatedTo): static
    {
        $this->delegatedTo = $delegatedTo;

        return $this;
    }

    public function getCompletedBy(): ?User
    {
        return $this->completedBy;
    }

    public function setCompletedBy(?User $completedBy): static
    {
        $this->completedBy = $completedBy;

        return $this;
    }

    /**
     * The single person on the hook for this task right now: the delegatee if delegated, otherwise the
     * concrete assignee (the person picked in the responsibility cascade, kept current by the handover).
     * Falls back to the first current holder of the structural responsibility only when there is no
     * concrete assignee (e.g. a vacated post). Null when nobody can be resolved.
     *
     * @return User|null the current responsible person, or null
     */
    public function resolveResponsible(): ?User
    {
        if (null !== $this->delegatedTo) {
            return $this->delegatedTo;
        }

        if (null !== $this->assignedUser) {
            return $this->assignedUser;
        }

        if (null !== $this->responsibility) {
            return $this->responsibility->holders()[0] ?? null;
        }

        return null;
    }

    public function getResponsibility(): ?TaskResponsibility
    {
        return $this->responsibility;
    }

    public function setResponsibility(?TaskResponsibility $responsibility): static
    {
        $this->responsibility = $responsibility;

        return $this;
    }

    /**
     * Who to show as responsible: the frozen {@see $completedBy} once the task is closed (a historical
     * fact that never changes), or the live {@see resolveResponsible()} while it is still open.
     *
     * @return User|null the person to display as responsible, or null
     */
    public function responsibleForDisplay(): ?User
    {
        return $this->completedBy ?? $this->resolveResponsible();
    }
}
