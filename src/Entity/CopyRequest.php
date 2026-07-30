<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CopyRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An order sent to the copy room (conserjería) so the auxiliares print something: normally the task a
 * group will be given during a guardia, sometimes just a document someone needs run off.
 *
 * {@see $copies} is mandatory by design — the centre's own question was whether the number of copies
 * was needed, and an order that does not say how many to print is useless to the person at the
 * photocopier. Everything else on the row exists to make the e-mail actionable without a reply:
 * {@see $context} says what it is for (level, group, room, period) and {@see $documentPath} is the file
 * that travels attached.
 *
 * The row is the record that the order was PLACED; {@see $sentAt} says whether the e-mail actually
 * went out. They are separate on purpose: a mail transport hiccup must not lose the order, it must
 * leave it visible and resendable. {@see $recipient} snapshots the address used, so a later change of
 * mailbox does not rewrite history.
 *
 * The document is referenced, not copied: an order that came from a guardia points at the very file
 * the covering teacher downloads. If that file is later removed (the parte line is deleted), the order
 * keeps its name and its trace — the e-mail carrying the attachment had already left.
 */
#[ORM\Entity(repositoryClass: CopyRequestRepository::class)]
#[ORM\Table(name: 'copy_request')]
#[ORM\Index(name: 'IDX_copy_requested_at', columns: ['requested_at'])]
#[ORM\Index(name: 'IDX_copy_cover', columns: ['cover_id'])]
#[ORM\Index(name: 'IDX_copy_bank_item', columns: ['bank_item_id'])]
#[ORM\Index(name: 'IDX_copy_requested_by', columns: ['requested_by_id'])]
class CopyRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * The guardia this order prints the task for, when it came from one. Null for a standalone order
     * (something the centre needs copied with no absence behind it); cleared if the parte line goes.
     */
    #[ORM\ManyToOne(targetEntity: GuardiaCover::class)]
    #[ORM\JoinColumn(name: 'cover_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?GuardiaCover $cover = null;

    /** The bank task being printed, when the document came from the bank. */
    #[ORM\ManyToOne(targetEntity: GuardiaTaskBankItem::class)]
    #[ORM\JoinColumn(name: 'bank_item_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?GuardiaTaskBankItem $bankItem = null;

    /**
     * How many copies to print. Mandatory by design — the centre's own open question — and validated on
     * the way in ({@see \App\Form\CopyRequestFormData}), so a stored order always carries a real number.
     */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $copies = 0;

    /** Anything the copy room needs to know ("a doble cara", "para la 3ª hora"). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    /** Storage-relative path of the document to print, or null for a text-only order. */
    #[ORM\Column(name: 'document_path', length: 255, nullable: true)]
    private ?string $documentPath = null;

    /** Original filename of the document, used both in the e-mail and in the listing. */
    #[ORM\Column(name: 'document_name', length: 255, nullable: true)]
    private ?string $documentName = null;

    /**
     * One-line summary of what the copies are for, snapshotted when the order is placed ("4º de ESO ·
     * E4D · aula A12 · 30/07, 3ª hora"). Read by the copy room in the subject line and by the app in
     * the listing, so neither depends on the cover still existing.
     */
    #[ORM\Column(length: 255)]
    private string $context = '';

    /** Who placed the order (kept for the reply-to and the listing); cleared if that user is removed. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'requested_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $requestedBy = null;

    #[ORM\Column(name: 'requested_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $requestedAt;

    /** When the e-mail actually left. Null while it has not (a failed send stays resendable). */
    #[ORM\Column(name: 'sent_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    /** The mailbox the order was sent to, snapshotted so a change of address does not rewrite history. */
    #[ORM\Column(length: 180)]
    private string $recipient = '';

    public function __construct()
    {
        $this->requestedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCover(): ?GuardiaCover
    {
        return $this->cover;
    }

    public function setCover(?GuardiaCover $cover): static
    {
        $this->cover = $cover;

        return $this;
    }

    public function getBankItem(): ?GuardiaTaskBankItem
    {
        return $this->bankItem;
    }

    public function setBankItem(?GuardiaTaskBankItem $bankItem): static
    {
        $this->bankItem = $bankItem;

        return $this;
    }

    public function getCopies(): int
    {
        return $this->copies;
    }

    public function setCopies(int $copies): static
    {
        $this->copies = $copies;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = null !== $notes && '' !== trim($notes) ? trim($notes) : null;

        return $this;
    }

    public function getDocumentPath(): ?string
    {
        return $this->documentPath;
    }

    public function setDocumentPath(?string $documentPath): static
    {
        $this->documentPath = $documentPath;

        return $this;
    }

    public function getDocumentName(): ?string
    {
        return $this->documentName;
    }

    public function setDocumentName(?string $documentName): static
    {
        $this->documentName = $documentName;

        return $this;
    }

    /**
     * Whether there is a file to print (as opposed to an order described only in words).
     *
     * @return bool true when a document is attached
     */
    public function hasDocument(): bool
    {
        return null !== $this->documentPath;
    }

    public function getContext(): string
    {
        return $this->context;
    }

    public function setContext(string $context): static
    {
        $this->context = mb_substr(trim($context), 0, 255);

        return $this;
    }

    public function getRequestedBy(): ?User
    {
        return $this->requestedBy;
    }

    public function setRequestedBy(?User $requestedBy): static
    {
        $this->requestedBy = $requestedBy;

        return $this;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    /**
     * Whether the e-mail to the copy room actually went out.
     *
     * @return bool true once sent
     */
    public function isSent(): bool
    {
        return null !== $this->sentAt;
    }

    /**
     * Marks the order as delivered to the copy room, at the given instant.
     *
     * @param \DateTimeImmutable $at when the e-mail left
     */
    public function markSent(\DateTimeImmutable $at): static
    {
        $this->sentAt = $at;

        return $this;
    }

    public function getRecipient(): string
    {
        return $this->recipient;
    }

    public function setRecipient(string $recipient): static
    {
        $this->recipient = $recipient;

        return $this;
    }
}
