<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TaskCommentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Something someone said about a task: the note that goes with a delivery, what has to be corrected
 * when it comes back for review, and the closing remark when it is finally accepted. The centre asked
 * for the conversation to live WITH the task and to have no limit ("puede haber tanta retroalimentación
 * como sea necesaria"), instead of leaking into e-mail where nobody can find it later.
 *
 * Distinct from the audit trail on purpose: the trail records what the SYSTEM saw change (status, dates,
 * who), automatically and unedited; this is what a PERSON chose to write. Mixing them would either bury
 * a teacher's message among field diffs or let a comment pretend to be an audited fact.
 *
 * Append-only by construction: no setter for the body and no delete route. What was said in a delivery
 * cycle is part of its record, the same as the trail — editing it afterwards would let one side rewrite
 * the conversation the other side already acted on.
 */
#[ORM\Entity(repositoryClass: TaskCommentRepository::class)]
#[ORM\Table(name: 'task_comment')]
// Serves the only query there is: the thread of one task, oldest first.
#[ORM\Index(name: 'idx_task_comment_task', columns: ['task_id', 'created_at'])]
class TaskComment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The task this was said about. Deleting the task takes its thread with it (it has no meaning alone). */
    #[ORM\ManyToOne(targetEntity: Task::class)]
    #[ORM\JoinColumn(name: 'task_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Task $task;

    /**
     * Who wrote it. Nullable ONLY so removing a person does not delete the thread they took part in
     * (same idiom as {@see Task::getCreatedBy()}); the constructor always demands one.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Escribe algo antes de enviar el comentario.')]
    #[Assert\Length(max: 2000, maxMessage: 'El comentario es demasiado largo (máximo {{ limit }} caracteres).')]
    private string $body;

    /**
     * The workflow transition this comment came with ("submit", "review", "validate"), or null when it
     * was written on its own. It is what lets the thread read as a conversation ("Entregada con esta
     * nota", "Devuelta para revisar") instead of a wall of anonymous paragraphs — and it is stored, not
     * derived from timestamps, because two things can happen in the same second.
     */
    #[ORM\Column(name: 'transition', length: 20, nullable: true)]
    private ?string $transition;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Task $task, User $author, string $body, ?string $transition = null)
    {
        $this->task = $task;
        $this->author = $author;
        $this->body = trim($body);
        $this->transition = $transition;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTask(): Task
    {
        return $this->task;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getTransition(): ?string
    {
        return $this->transition;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * How to introduce this comment in the thread, from the transition it came with.
     *
     * @return string a short Spanish lead-in
     */
    public function lead(): string
    {
        return match ($this->transition) {
            'submit' => 'al entregarla',
            'review' => 'al devolverla para revisar',
            'validate' => 'al darla por finalizada',
            default => '',
        };
    }
}
