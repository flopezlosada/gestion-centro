<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MeetingRemarkRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An observation on a PUBLISHED acta: "en el punto 3 no se acordó eso", "falta mi voto en contra", "el
 * nombre del aula está mal".
 *
 * It exists because of one rule of the centre and one thing that rule leaves open: once the acta is sent,
 * only whoever coordinates or convened may change its text — but the people who were at the meeting are
 * precisely the ones who notice a mistake in it. Without somewhere to say so, the correction travels by
 * corridor or by e-mail and never reaches the record.
 *
 * Deliberately NOT a second version of the acta. An observation does not change the text, is not part of
 * what was agreed, and does NOT go into the generated PDF: the acta is what the body approved, and folding
 * everybody's objections into it would turn the institutional record into a thread. What it does is put
 * the objection next to the acta, with its author and its date, in front of the two people who can act on
 * it — who then correct the text and publish it again, and their correction is what the record keeps.
 *
 * {@see TaskComment} is the same SHAPE (append-only, author nullable, thread read oldest-first) and the
 * form was taken from it, but not its `transition` field: a meeting has no workflow to hang a remark off,
 * and a nullable column that is always null is a column that lies about being useful.
 */
#[ORM\Entity(repositoryClass: MeetingRemarkRepository::class)]
#[ORM\Table(name: 'meeting_remark')]
// Serves the only query there is: the observations of one meeting, oldest first.
#[ORM\Index(name: 'idx_meeting_remark_meeting', columns: ['meeting_id', 'created_at'])]
class MeetingRemark
{
    /**
     * Ceiling for an observation. Shorter than a task comment's on purpose: this is "el punto 3 dice X y
     * se acordó Y", not a discussion — what needs more room than this is a correction to ask for in
     * person, or the next meeting's business.
     */
    public const int MAX_LENGTH = 1000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * The meeting whose acta this is about. Deleting the meeting takes its observations with it: they have
     * no meaning without the acta they point at, same as its own minutes file.
     */
    #[ORM\ManyToOne(targetEntity: Meeting::class)]
    #[ORM\JoinColumn(name: 'meeting_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Meeting $meeting;

    /**
     * Who wrote it. Nullable ONLY so removing a person does not delete the objection they raised (same
     * idiom as {@see Meeting::getConvener()}); the constructor always demands one.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $author;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Escribe la observación antes de enviarla.')]
    #[Assert\Length(max: self::MAX_LENGTH, maxMessage: 'La observación es demasiado larga (máximo {{ limit }} caracteres).')]
    private string $body;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Meeting $meeting, User $author, string $body, \DateTimeImmutable $at)
    {
        $this->meeting = $meeting;
        $this->author = $author;
        $this->body = trim($body);
        $this->createdAt = $at;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMeeting(): Meeting
    {
        return $this->meeting;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    /**
     * The text of the observation. There is no setter, and no route that deletes one: an objection to a
     * published acta is part of how the record was corrected, and letting either side rewrite it afterwards
     * would let them rewrite an exchange the other side already acted on.
     */
    public function getBody(): string
    {
        return $this->body;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
