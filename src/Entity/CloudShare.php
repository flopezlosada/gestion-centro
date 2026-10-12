<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CloudShareRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A share of an acta in the EducaMadrid cloud that has not been made yet: one document, one person.
 *
 * It exists because the cloud lets an account create only 20 shares every 10 minutes, and a claustro is
 * some 80 people. Sharing is therefore a queue: what fits goes at once, the rest waits here and the
 * scheduled task makes it as the cloud allows ({@see \App\Service\Cloud\CloudShareQueue}).
 *
 * A row is deleted once the share is made: what is shared is the cloud's to say, not a copy kept here.
 * What stays is what still has to happen — pending — or what cannot, with the cloud's reason, so whoever
 * keeps the minutes sees who was left out and why.
 */
#[ORM\Entity(repositoryClass: CloudShareRepository::class)]
#[ORM\Table(name: 'cloud_share')]
// Un mismo documento no se comparte dos veces con la misma persona.
#[ORM\UniqueConstraint(name: 'uniq_cloud_share_file_person', columns: ['file_id', 'person_id'])]
class CloudShare
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Deleting the meeting takes its pending shares with it: there is nothing left to share. */
    #[ORM\ManyToOne(targetEntity: Meeting::class)]
    #[ORM\JoinColumn(name: 'meeting_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Meeting $meeting;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'person_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $person;

    /** The document, by its id in the cloud: it may be moved or renamed there before its turn comes. */
    #[ORM\Column(name: 'file_id', length: 32)]
    private string $fileId;

    /** {@see \App\Service\Cloud\EducamadridCloud::PERMISSION_READ} or PERMISSION_EDIT. */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $permissions;

    /** Whether the person is told "ya puedes leer el acta" once it is shared — and not before. */
    #[ORM\Column]
    private bool $notify;

    /** Why it cannot be shared, or null while it is still to be tried. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $problem = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Meeting $meeting, User $person, string $fileId, int $permissions, bool $notify, \DateTimeImmutable $at)
    {
        $this->meeting = $meeting;
        $this->person = $person;
        $this->fileId = $fileId;
        $this->permissions = $permissions;
        $this->notify = $notify;
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

    public function getPerson(): User
    {
        return $this->person;
    }

    public function getFileId(): string
    {
        return $this->fileId;
    }

    public function getPermissions(): int
    {
        return $this->permissions;
    }

    public function notifies(): bool
    {
        return $this->notify;
    }

    public function getProblem(): ?string
    {
        return $this->problem;
    }

    /**
     * Gives up on this share, with the reason to show. Cut to the column: the reason is the cloud's text.
     */
    public function fail(string $problem): void
    {
        $this->problem = mb_substr($problem, 0, 255);
    }

    public function isPending(): bool
    {
        return null === $this->problem;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
