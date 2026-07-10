<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An append-only record of a single security/admin event (login, role or permission change,
 * configuration change, …). It is the system's tamper-evident activity trail (non-repudiation).
 *
 * Per-task history is not stored here: that lives in the richer {@see TaskEvent} timeline.
 * Entries are immutable: they are created once and never updated or deleted through the app.
 */
#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'audit_log')]
#[ORM\Index(name: 'idx_audit_occurred_at', columns: ['occurred_at'])]
// Supports findForSubject(): lists a subject's entries newest-first.
#[ORM\Index(name: 'idx_audit_subject', columns: ['subject_type', 'subject_id', 'occurred_at'])]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    /**
     * Who performed the action (user identifier / e-mail), or null for system/anonymous events.
     */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $actor;

    /**
     * Machine-readable event name, e.g. "user.login", "role.updated".
     */
    #[ORM\Column(length: 100)]
    private string $action;

    /**
     * Affected entity type and id, when the event concerns one (e.g. "User", "42").
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $subjectType;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $subjectId;

    /**
     * Human-readable, Spanish summary shown in the activity view.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary;

    /**
     * Field-level diff for automatic entity-change events, keyed by property name. Scalar/to-one
     * fields use {@code ["field" => ["old" => mixed, "new" => mixed]]}; to-many/collection fields use
     * {@code ["field" => ["added" => list, "removed" => list]]}. Null for named events with no diff.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $changes;

    /**
     * @param array<string, mixed>|null $changes field-level diff, if any
     */
    public function __construct(
        string $action,
        ?string $actor = null,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?string $summary = null,
        ?array $changes = null,
    ) {
        $this->action = $action;
        $this->actor = $actor;
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->summary = $summary;
        $this->changes = $changes;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getActor(): ?string
    {
        return $this->actor;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getSubjectType(): ?string
    {
        return $this->subjectType;
    }

    public function getSubjectId(): ?string
    {
        return $this->subjectId;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    /**
     * @return array<string, mixed>|null the field-level diff, if any
     */
    public function getChanges(): ?array
    {
        return $this->changes;
    }
}
