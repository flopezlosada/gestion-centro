<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EducationLevel;
use App\Repository\TopicRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One topic ("tema") of a subject at a level — "Ecuaciones" of Matemáticas in 3º de ESO — in the list
 * every teacher of that subject and level shares to plan their classes.
 *
 * Nobody has to prepare the list: a teacher creates a topic the first time they plan a class on it, and
 * from then on it is one tap for them and for everybody else teaching the same subject and level. The
 * head of department tidies it (order, names, merging duplicates, retiring old ones); a class points to
 * its topic by id, so none of that ever loses what was recorded.
 *
 * Scoped by subject NAME, as the timetable spells it, and level: those are what a class knows about
 * itself. No database uniqueness on the name, on purpose: the level can be null (a group the level
 * reader does not recognise) and MariaDB lets NULLs repeat in a unique index, so the rule "same name in
 * the same list is the same topic" lives in {@see \App\Service\TopicCatalog}, compared without case or
 * accents, like the database compares text.
 *
 * Retired, never deleted once used: the classes already planned on it keep their topic.
 */
#[ORM\Entity(repositoryClass: TopicRepository::class)]
#[ORM\Table(name: 'topic')]
#[ORM\Index(name: 'idx_topic_scope', columns: ['subject', 'level'])]
class Topic
{
    /** Longest topic name kept. */
    public const int MAX_NAME = 120;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $subject;

    #[ORM\Column(length: 16, nullable: true, enumType: EducationLevel::class)]
    private ?EducationLevel $level;

    #[ORM\Column(length: self::MAX_NAME)]
    private string $name;

    /** Place in the list: the order the topics are taught in, which the progress ("tema 4 de 9") reads. */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $position;

    #[ORM\Column(options: ['default' => false])]
    private bool $retired = false;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param string              $subject   the subject, as the timetable spells it
     * @param EducationLevel|null $level     the level, or null when the group's level is not recognised
     * @param string              $name      the topic's name
     * @param int                 $position  its place in the list
     * @param User|null           $createdBy who created it
     */
    public function __construct(string $subject, ?EducationLevel $level, string $name, int $position, ?User $createdBy)
    {
        $this->subject = $subject;
        $this->level = $level;
        $this->name = mb_substr(trim($name), 0, self::MAX_NAME);
        $this->position = $position;
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getLevel(): ?EducationLevel
    {
        return $this->level;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function isRetired(): bool
    {
        return $this->retired;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    /**
     * Renames it, trimmed and capped, exactly as a teacher's typed name is stored.
     *
     * @param string $name the new name
     */
    public function rename(string $name): static
    {
        $this->name = mb_substr(trim($name), 0, self::MAX_NAME);

        return $this;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function retire(): static
    {
        $this->retired = true;

        return $this;
    }

    public function reinstate(): static
    {
        $this->retired = false;

        return $this;
    }
}
